# BDC IMS - Gemini Agent Context

This document provides a comprehensive overview of the BDC IMS (Build, Deploy, Configure Inventory Management System) project for the Gemini agent.

## Project Overview

The BDC IMS is a PHP-based web application designed to manage a comprehensive inventory of server components. It provides a robust API for creating, managing, and tracking server configurations, ensuring compatibility between various components like CPUs, motherboards, RAM, and storage devices. The system is built with a focus on modularity and extensibility, allowing for the easy addition of new component types and compatibility rules.

### Key Features

*   **Inventory Management:** A complete system for tracking server components, including CPUs, RAM, storage, motherboards, NICs, and more. Each component has a detailed set of specifications stored in JSON files.
*   **Server Configuration:** A powerful tool for building virtual server configurations. The system guides the user through the process of selecting compatible components, allocating resources like PCIe slots, and validating the final configuration.
*   **Compatibility Engine:** A sophisticated compatibility engine that checks for compatibility between different components based on a set of predefined rules.
*   **RESTful API:** A comprehensive RESTful API with over 80 endpoints for managing all aspects of the system, including components, users, and server configurations.
*   **Authentication & Authorization:** The API is secured using JWT (JSON Web Tokens) for authentication and a role-based access control (RBAC) system for authorization.
*   **Modular Architecture:** The application is designed with a modular architecture, making it easy to add new component types and extend the functionality of the system.

## Technologies Used

*   **Backend:** PHP
*   **Database:** MySQL
*   **API Format:** RESTful API with JSON
*   **Authentication:** JWT (JSON Web Tokens)

## Project Structure

The project is organized into the following directories:

*   `api/`: Contains the main API entry point (`api.php`) and the API handlers for each module.
*   `includes/`: Contains the core application logic, including the database configuration, utility functions, and model classes.
*   `All-JSON/`: Contains JSON files with the specifications for each component type.
*   `claude/`: Contains documentation files, including the API reference, architecture overview, and database schema.
*   `tests/`: Contains unit tests for the application.

## Building and Running the Project

The project is a standard PHP application that can be run on any web server with PHP and MySQL support.

### Prerequisites

*   PHP 7.4 or higher
*   MySQL 5.7 or higher
*   A web server (e.g., Apache, Nginx)

### Installation

1.  Clone the repository to your local machine.
2.  Create a MySQL database and import the `shubhams_ims_dev.sql` file to set up the database schema.
3.  Create a `.env` file in the project root by copying the `.env.example` file (if it exists) or by creating a new one.
4.  Update the `.env` file with your database credentials and other configuration settings.
5.  Point your web server's document root to the project's root directory.

### Running the Application

The application can be accessed through the web server. The API endpoint is located at `https://your-domain.com/api/api.php`.

## Development Conventions

*   **Coding Style:** The project follows the PSR-12 coding style guide.
*   **API Design:** The API is designed to be RESTful and uses JSON for all requests and responses.
*   **Authentication:** All API endpoints (except for the `auth` module) require a valid JWT token to be passed in the `Authorization` header.
*   **Error Handling:** The API returns standard HTTP status codes to indicate the success or failure of a request. Error messages are returned in a consistent JSON format.
*   **Database:** The project uses PDO for database access and follows the convention of using model classes to interact with the database.
*   **Documentation:** The `claude/` directory contains detailed documentation for the project, including the API reference, architecture overview, and database schema.

## Testing

The project includes a set of unit tests in the `tests/` directory. The tests are written using PHPUnit and can be run from the command line.

### Running the Tests

To run the tests, you will need to have PHPUnit installed. You can then run the tests by executing the following command in the project's root directory:

```bash
vendor/bin/phpunit
```

*Note: This is a placeholder command. You may need to adjust it based on your project's setup.*
