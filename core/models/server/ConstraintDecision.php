<?php
/**
 * ConstraintDecision
 * File: core/models/server/ConstraintDecision.php
 *
 * Return value of ServerConfigConstraintState::canAddComponent() and the
 * compatibility adapter. Deliberately small and plain-array friendly so it
 * can be serialised straight into an API response.
 */

class ConstraintDecision
{
    /** @var bool */
    public $allowed = true;

    /** @var string[] Blocking reasons — if non-empty, $allowed MUST be false. */
    public $issues = [];

    /** @var string[] Non-blocking advisories (e.g. spec drift, soft ECC hints). */
    public $warnings = [];

    /** @var string[] Actionable suggestions shown in UI. */
    public $recommendations = [];

    /**
     * @var array Structured detail surface used by the UI to render budget
     *            strips ("32/32 RAM slots used", "180W / 250W TDP", ...).
     *            Keys are free-form; prefer snake_case.
     */
    public $details = [];

    public static function allow(array $details = []): self
    {
        $d = new self();
        $d->allowed = true;
        $d->details = $details;
        return $d;
    }

    public static function deny(string $issue, array $details = []): self
    {
        $d = new self();
        $d->allowed = false;
        $d->issues[] = $issue;
        $d->details = $details;
        return $d;
    }

    public function addIssue(string $message): self
    {
        $this->issues[] = $message;
        $this->allowed = false;
        return $this;
    }

    public function addWarning(string $message): self
    {
        $this->warnings[] = $message;
        return $this;
    }

    public function addRecommendation(string $message): self
    {
        $this->recommendations[] = $message;
        return $this;
    }

    public function mergeDetails(array $details): self
    {
        $this->details = array_replace($this->details, $details);
        return $this;
    }

    /**
     * Combine another decision into this one. $allowed becomes the AND of the
     * two; issues / warnings / recommendations are concatenated; details are
     * shallow-merged with the incoming one winning on key collision.
     */
    public function merge(ConstraintDecision $other): self
    {
        $this->allowed = $this->allowed && $other->allowed;
        $this->issues          = array_merge($this->issues,          $other->issues);
        $this->warnings        = array_merge($this->warnings,        $other->warnings);
        $this->recommendations = array_merge($this->recommendations, $other->recommendations);
        $this->details         = array_replace($this->details,       $other->details);
        return $this;
    }

    public function toArray(): array
    {
        return [
            'allowed'         => $this->allowed,
            'issues'          => $this->issues,
            'warnings'        => $this->warnings,
            'recommendations' => $this->recommendations,
            'details'         => $this->details,
        ];
    }
}
