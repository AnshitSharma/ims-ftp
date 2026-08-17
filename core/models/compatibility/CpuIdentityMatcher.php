<?php

require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../shared/DataNormalizationUtils.php';

/**
 * Decides whether two CPUs may share a motherboard.
 *
 * Socket match is necessary but NOT sufficient: once socket 1 is populated, CPU #1
 * becomes the constraint for every remaining socket. Xeon Gold 6338 and 6342 are both
 * LGA4189 and both Ice Lake-SP, yet cannot run together -- multi-socket operation
 * requires the same SKU. Only the vendor's "processor line suffix" may differ, and even
 * that is not a validated combination.
 *
 * Three verdicts:
 *   IDENTICAL  same SKU (the normal 2S build)             -> allow silently
 *   VARIANT    same base SKU, different suffix (6338/6338N) -> allow with a warning
 *   MISMATCH   anything else (6338/6342)                    -> block
 *
 * Single authority on purpose: called from the live legacy path
 * (ServerBuilder::validateCPUAddition) AND from CpuMixedModelsRule in the new engine,
 * so the two cannot drift apart on what "pairable" means.
 */
class CpuIdentityMatcher
{
    const VERDICT_IDENTICAL = 'identical';
    const VERDICT_VARIANT   = 'variant';
    const VERDICT_MISMATCH  = 'mismatch';

    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct($dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?: new DataExtractionUtilities();
    }

    /**
     * Split a CPU model string into its comparable parts.
     *
     * Must survive every naming shape present in ims-data/cpu/Cpu-details-level-3.json:
     *   "Gold 6338"      -> tier gold,    base 6338,  suffix ''
     *   "Gold 6338N"     -> tier gold,    base 6338,  suffix 'N'
     *   "Platinum 8480+" -> tier platinum,base 8480,  suffix '+'
     *   "Platinum 8173M" -> tier platinum,base 8173,  suffix 'M'
     *   "E5-2680 v3"     -> tier e5,      base 2680,  suffix '',   version v3
     *   "EPYC 9374F"     -> tier epyc,    base 9374,  suffix 'F'
     *   "Ryzen 9 5900XT" -> tier ryzen9,  base 5900,  suffix 'XT'
     *   "Core i5-12500"  -> tier corei5,  base 12500, suffix ''
     *
     * @return array{tier:string, base_sku:string, suffix:string, version:string, parsed:bool}
     */
    public function parse($model)
    {
        $blank = ['tier' => '', 'base_sku' => '', 'suffix' => '', 'version' => '', 'parsed' => false];

        if (!is_string($model) || trim($model) === '') {
            return $blank;
        }

        $work = trim($model);

        // Pull the Xeon E5 generation marker out first ("E5-2680 v3"), so it is compared
        // separately and never mistaken for part of the SKU number.
        $version = '';
        if (preg_match('/\bv(\d+)\b/i', $work, $vMatch)) {
            $version = 'v' . $vMatch[1];
            $work = trim(preg_replace('/\bv\d+\b/i', '', $work));
        }

        // The SKU is the LAST run of 3-5 digits plus any trailing suffix letters.
        // The 3-digit floor is what keeps "E5-" and "Ryzen 9" from being read as the SKU.
        if (!preg_match_all('/(\d{3,5})([A-Za-z+]*)/', $work, $matches, PREG_SET_ORDER)) {
            return $blank;
        }

        $last = $matches[count($matches) - 1];
        $baseSku = $last[1];
        $suffix  = strtoupper($last[2]);

        // Everything ahead of the SKU is the product tier (Gold / Platinum / EPYC / Core i5).
        $tierRaw = substr($work, 0, strrpos($work, $last[0]));
        $tier = strtolower(preg_replace('/[^a-z0-9]/i', '', $tierRaw));

        return [
            'tier'     => $tier,
            'base_sku' => $baseSku,
            'suffix'   => $suffix,
            'version'  => strtolower($version),
            'parsed'   => true,
        ];
    }

    /**
     * Compare two CPU spec arrays as loaded from ims-data.
     *
     * @param array $existing spec of a CPU already in the configuration
     * @param array $incoming spec of the CPU being added
     * @return array{verdict:string, compatible:bool, warning:?string, error:?string, details:array}
     */
    public function compare($existing, $incoming)
    {
        $existingUuid = $this->specUuid($existing);
        $incomingUuid = $this->specUuid($incoming);

        // Same catalogue entry: the ordinary matched-pair build.
        if ($existingUuid !== null && $existingUuid === $incomingUuid) {
            return $this->result(self::VERDICT_IDENTICAL, true, null, null, [
                'existing_model' => $this->specModel($existing),
                'incoming_model' => $this->specModel($incoming),
            ]);
        }

        $existingModel = $this->specModel($existing);
        $incomingModel = $this->specModel($incoming);

        $a = $this->parse($existingModel);
        $b = $this->parse($incomingModel);

        $details = [
            'existing_model' => $existingModel,
            'incoming_model' => $incomingModel,
            'existing_parsed' => $a,
            'incoming_parsed' => $b,
        ];

        // Fail safe: an unrecognised model name is never waved through. Different UUIDs
        // plus an unparseable name means we cannot prove pairability, so we refuse.
        if (!$a['parsed'] || !$b['parsed']) {
            return $this->result(self::VERDICT_MISMATCH, false, null,
                "Cannot verify CPU pairing: model name '" .
                (!$a['parsed'] ? $existingModel : $incomingModel) .
                "' could not be interpreted. Multi-socket configurations require identical CPU models.",
                $details);
        }

        $sameFamily = $this->familyKey($existing) === $this->familyKey($incoming);
        $sameSku    = ($a['tier'] === $b['tier'])
                   && ($a['base_sku'] === $b['base_sku'])
                   && ($a['version'] === $b['version']);

        if (!$sameFamily || !$sameSku) {
            return $this->result(self::VERDICT_MISMATCH, false, null,
                "Incompatible CPU pairing: {$incomingModel} cannot be installed alongside {$existingModel}. " .
                "Multi-socket configurations require the same CPU model — a matching socket is not sufficient.",
                $details);
        }

        // Parsing says "same SKU, different suffix". Confirm against the spec fields before
        // trusting it, so a name that merely looks like a variant cannot slip through.
        $specCheck = $this->specsAgree($existing, $incoming);
        if (!$specCheck['agree']) {
            return $this->result(self::VERDICT_MISMATCH, false, null,
                "Incompatible CPU pairing: {$incomingModel} and {$existingModel} share a base SKU but their " .
                "specifications differ ({$specCheck['reason']}). Multi-socket configurations require the same CPU model.",
                array_merge($details, ['spec_conflict' => $specCheck['reason']]));
        }

        $suffixA = $a['suffix'] !== '' ? $a['suffix'] : '(none)';
        $suffixB = $b['suffix'] !== '' ? $b['suffix'] : '(none)';

        $divergences = $this->describeDivergences($existing, $incoming);
        $detail = !empty($divergences) ? ' Differences: ' . implode('; ', $divergences) . '.' : '';

        return $this->result(self::VERDICT_VARIANT, true,
            "Possible issues: {$incomingModel} and {$existingModel} share base SKU {$a['base_sku']} but are " .
            "different SKU variants (suffix {$suffixB} vs {$suffixA}). Mixing processor line suffixes is not a " .
            "vendor-validated combination and may cause instability, or the system running both sockets at the " .
            "lower of the two capabilities.{$detail}",
            null, array_merge($details, ['divergences' => $divergences]));
    }

    /**
     * Convenience wrapper: compare two CPUs by their ims-data spec UUIDs.
     */
    public function compareByUuid($existingUuid, $incomingUuid)
    {
        $existing = $this->dataUtils->getCPUByUUID($existingUuid);
        $incoming = $this->dataUtils->getCPUByUUID($incomingUuid);

        if (!is_array($existing) || !is_array($incoming)) {
            return $this->result(self::VERDICT_MISMATCH, false, null,
                'Cannot verify CPU pairing: one of the CPUs was not found in the component specifications.',
                ['existing_uuid' => $existingUuid, 'incoming_uuid' => $incomingUuid]);
        }

        return $this->compare($existing, $incoming);
    }

    /**
     * Product line a CPU belongs to. Note findInBrandModels() merges brand/series/family
     * from the group into the model but NOT generation, so architecture (a model-level
     * field) carries the generation signal here.
     */
    private function familyKey($spec)
    {
        $parts = [];
        foreach (['brand', 'series', 'family', 'architecture'] as $field) {
            $value = isset($spec[$field]) && is_scalar($spec[$field]) ? (string)$spec[$field] : '';
            $parts[] = strtolower(trim($value));
        }
        return implode('|', $parts);
    }

    /**
     * Cross-check the spec fields that must hold across sockets regardless of suffix.
     */
    private function specsAgree($existing, $incoming)
    {
        $socketA = DataNormalizationUtils::normalizeSocketType($existing['socket'] ?? '');
        $socketB = DataNormalizationUtils::normalizeSocketType($incoming['socket'] ?? '');
        if ($socketA !== '' && $socketB !== '' && $socketA !== $socketB) {
            return ['agree' => false, 'reason' => "socket {$existing['socket']} vs {$incoming['socket']}"];
        }

        $archA = strtolower(trim((string)($existing['architecture'] ?? '')));
        $archB = strtolower(trim((string)($incoming['architecture'] ?? '')));
        if ($archA !== '' && $archB !== '' && $archA !== $archB) {
            return ['agree' => false, 'reason' => "architecture {$existing['architecture']} vs {$incoming['architecture']}"];
        }

        $chanA = isset($existing['memory_channels']) ? (int)$existing['memory_channels'] : 0;
        $chanB = isset($incoming['memory_channels']) ? (int)$incoming['memory_channels'] : 0;
        if ($chanA > 0 && $chanB > 0 && $chanA !== $chanB) {
            return ['agree' => false, 'reason' => "memory channels {$chanA} vs {$chanB}"];
        }

        // Deliberately NOT checked here: memory speed and core count. Suffix variants
        // legitimately differ on both -- 6338 is DDR4-3200/32C while 6338N is DDR4-2666
        // and 6338T is 24C -- and that divergence is the very thing the VARIANT warning
        // exists to report. Blocking on it would collapse the warning tier into the
        // mismatch tier. Only what must physically hold across sockets is enforced above.
        return ['agree' => true, 'reason' => ''];
    }

    /**
     * The spec differences worth telling an operator about when pairing SKU variants --
     * these are allowed, but they are what will actually bite in the rack.
     *
     * @return string[]
     */
    private function describeDivergences($existing, $incoming)
    {
        $out = [];

        $memA = $this->normalizedMemoryTypes($existing);
        $memB = $this->normalizedMemoryTypes($incoming);
        if (!empty($memA) && !empty($memB) && empty(array_intersect($memA, $memB))) {
            $out[] = 'memory support ' . implode('/', $existing['memory_types']) .
                     ' vs ' . implode('/', $incoming['memory_types']) . ' (both sockets run at the slower speed)';
        }

        foreach ([
            'cores' => 'core count',
            'threads' => 'thread count',
            'tdp_W' => 'TDP (W)',
        ] as $field => $label) {
            $valA = isset($existing[$field]) ? (int)$existing[$field] : 0;
            $valB = isset($incoming[$field]) ? (int)$incoming[$field] : 0;
            if ($valA > 0 && $valB > 0 && $valA !== $valB) {
                $out[] = "{$label} {$valA} vs {$valB}";
            }
        }

        return $out;
    }

    private function normalizedMemoryTypes($spec)
    {
        if (!isset($spec['memory_types']) || !is_array($spec['memory_types'])) {
            return [];
        }
        $out = [];
        foreach ($spec['memory_types'] as $type) {
            if (is_scalar($type)) {
                $out[] = strtolower(trim((string)$type));
            }
        }
        return $out;
    }

    /** CPU specs use an uppercase UUID key; tolerate both. */
    private function specUuid($spec)
    {
        if (!is_array($spec)) {
            return null;
        }
        return $spec['UUID'] ?? $spec['uuid'] ?? null;
    }

    private function specModel($spec)
    {
        if (!is_array($spec)) {
            return '';
        }
        return isset($spec['model']) ? (string)$spec['model'] : '';
    }

    private function result($verdict, $compatible, $warning, $error, $details)
    {
        return [
            'verdict'    => $verdict,
            'compatible' => $compatible,
            'warning'    => $warning,
            'error'      => $error,
            'details'    => $details,
        ];
    }
}
