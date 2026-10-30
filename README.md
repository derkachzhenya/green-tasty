# Green Tasty

### Restaurant Reservation & Group Pre-Order System

A full-stack restaurant reservation and group pre-order web application developed as part of **EPAM IT Marathon 2026**.

Green Tasty allows customers to discover restaurants, make reservations, invite guests, select dishes, and manage group pre-orders through a modern and responsive interface.

## Features

- **Restaurant Discovery:** Browse restaurants and explore available booking options.
- **User Authentication:** Registration, login, email verification, password recovery, and account management.
- **Restaurant Reservations:** Create, manage, update, and cancel reservations.
- **Guest Invitations:** Invite guests to participate in a group reservation using secure invitation links.
- **Group Pre-Orders:** Allow guests to select dishes individually before their restaurant visit.
- **Consolidated Order Summary:** Aggregate guest selections, quantities, and total prices.
- **Reservation Dashboard:** View and manage reservations and group pre-orders.
- **Staff Functionality:** Dedicated restaurant staff workflows.
- **Automated Testing:** Backend and frontend tests covering application functionality.

## Technology Stack

| Layer | Technologies |
|---|---|
| Backend | PHP 8.3+, Laravel 13 |
| Frontend | Vue 3, TypeScript |
| Full-stack Integration | Inertia.js |
| Styling | Tailwind CSS 4 |
| Database | MySQL 8.4 |
| Development Environment | Docker, Laravel Sail |
| Build Tools | Vite, Vite Plus |
| Testing | PHPUnit, Vitest |
| Deployment | AWS |

## Getting Started

### Prerequisites

- Docker Desktop
- PHP and Composer
- Git

### Installation

**1. Clone the repository**

`git clone https://github.com/derkachzhenya/green-tasty.git`

`cd green-tasty`

**2. Install PHP dependencies**

`composer install`

**3. Create the environment configuration**

`cp .env.example .env`

Configure the following values in `.env` for Laravel Sail:

- `DB_CONNECTION=mysql`
- `DB_HOST=mysql`
- `DB_PORT=3306`
- `DB_DATABASE=laravel`
- `DB_USERNAME=sail`
- `DB_PASSWORD=password`

These are example local development credentials. Use secure, environment-specific credentials for production.

**4. Start Docker containers**

`./vendor/bin/sail up -d`

**5. Generate the application key**

`./vendor/bin/sail artisan key:generate`

**6. Run database migrations**

`./vendor/bin/sail artisan migrate`

**7. Install frontend dependencies**

`./vendor/bin/sail npm install`

**8. Start the frontend development server**

`./vendor/bin/sail npm run dev`

Open `http://localhost` in your browser, assuming the default Sail port configuration.

## Running Tests

Run the Laravel test suite:

`./vendor/bin/sail artisan test`

Run frontend unit tests:

`./vendor/bin/sail npm run test:unit`

Run frontend validation and type checks:

`./vendor/bin/sail npm run check`

`./vendor/bin/sail npm run types:check`

## Project Structure

- `app/` - Laravel application logic, controllers, models, and services.
- `database/` - Database migrations, factories, and seeders.
- `resources/` - Vue components, pages, and frontend assets.
- `routes/` - Application routes.
- `tests/` - Automated tests.
- `docs/` - Project documentation.

## Security

The application includes protected routes, authorization checks, request throttling, and secure guest invitation workflows.

Environment secrets and production credentials must not be committed to the repository.

## Project Information

**Project:** Green Tasty

**Program:** EPAM IT Marathon 2026

**Repository:** https://github.com/derkachzhenya/green-tasty

**Developer:** Yevhen Derkach

---

Developed for EPAM IT Marathon 2026.
