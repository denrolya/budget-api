
# Financial Tracker Application

This financial tracker application supports multiple currencies: EUR, USD, UAH, HUF, and BTC.
It allows users to manage various accounts, track debts, and categorize transactions efficiently.

## Table of Contents

1. [Entities](#entities)
2. [Budgeting Feature](#budgeting-feature)
3. [Redundant Tables](#redundant-tables)
4. [Running Tests](#running-tests)
5. [Production Bank Jobs (Cron)](#production-bank-jobs-cron)
6. [Setting Up SonarQube](#setting-up-sonarqube)
    - [Using Docker](#using-docker)
    - [Using Docker Compose](#using-docker-compose)
7. [Running SonarQube Analysis](#running-sonarqube-analysis)
---

## Entities

The application consists of the following main entities:

- **User**:
    - Users can set and update their base currency at any time.

- **Account**:
    - Each user can have multiple accounts with different balances.
    - Account types include:
        - **Cash**
        - **Bank**: Contains fields like IBAN, card number, and bank name.
        - **Internet**: Includes a field for specifying the provider (e.g., Wise, PayPal).
        - **Other**

- **Debt**:
    - Functions similarly to an account but tracks the historical value of money lent or borrowed at the time of the transaction.
    - It is linked to specific transactions.

- **Category**:
    - Organizes transactions into a tree structure with parent and root relationships.
    - Categories can be of type **expense** or **income**.
    - Attributes include:
        - **isAffectingProfit**: Determines if the category should impact statistical calculations.
            - Categories such as Debt, Transfer, and Compensation do not affect profit.
        - **isTechnical**: Flags categories used for internal processes like Transfer and Debt.
        - **Icon and Color**: Used for visual representation in older versions of the app.
    - Note: The **isFixed** field is currently unused and includes categories like Rent, Internet, and Haircut.

- **Transaction**:
    - Represents financial activities and can be categorized as **Income** or **Expense**.
    - Transactions can be marked as draft (`isDraft`), meaning they do not affect statistics, or compensated.
    - Attributes:
        - **Associations**: Linked with **Category**, **Account**, and **User** entities.
        - **Execution Dates**: Includes fields like `createdAt`, `updatedAt`, and `executedAt`.
        - **Converted Values**: Stores amounts in different currencies based on exchange rates.
    - **Exchange Rates**: Currently retrieved from Fixer API, with plans to support Monobank or NBU rates.

- **Transfer**:
    - Links two transactions (an income and an expense) to represent the movement between accounts, including rate and fee details.
    - Future updates will aim to remove the `from` and `to` account fields and use the linked transactions for this information.

## Budgeting Feature

The budgeting feature allows users to plan their finances by defining spending and income targets per category for a given time period.

### Entities

- **Budget**:
    - Represents a financial plan for a specific time period.
    - Period types: `monthly`, `yearly`, `custom`.
    - Attributes: `name` (optional), `periodType`, `startDate`, `endDate`.
    - Contains a collection of **BudgetLine** entries (cascade delete).

- **BudgetLine**:
    - Represents a single planned amount for a specific category within a budget.
    - Attributes: `category`, `plannedAmount`, `plannedCurrency`.
    - Each `(budget, category)` combination must be unique.

### API Endpoints

All endpoints are prefixed with `/api/v2/budget` and require authentication.

#### Budget CRUD

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List all budgets (sorted by start date descending) |
| GET | `/{id}` | Get a single budget with all its lines |
| POST | `/` | Create a new budget |
| PUT | `/{id}` | Update budget properties |
| DELETE | `/{id}` | Delete a budget (cascades to lines) |

#### Budget Line CRUD

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/{id}/line` | Add a line to a budget |
| PUT | `/{id}/line/{lineId}` | Update a budget line |
| DELETE | `/{id}/line/{lineId}` | Remove a line from a budget |

#### Analytics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/{id}/analytics` | Get actual spending grouped by category for the budget's period |

The analytics endpoint returns transaction totals (income and expense) per category and currency for the budget's date range, using only profit-affecting transactions. This allows comparing planned amounts against actuals.

### Key Features

- **Multi-period support**: Monthly, yearly, and custom date ranges.
- **Multi-currency**: Each budget line can be in a different currency.
- **Budget templates**: When creating a budget, pass `copiedFromId` to clone all lines from an existing budget.
- **Actual vs. planned**: The analytics endpoint aggregates real transactions for comparison with planned amounts.
- **Cascade deletion**: Deleting a budget automatically removes all associated lines.

---

## Redundant Tables

There are outdated tables such as **currency** that should be considered for removal.

---

## Running Tests

To run the tests for your project, execute the following command in the terminal:

```bash
composer test
```

For generating clover coverage reports, run:

```bash
composer test-clover
```

## Production Bank Jobs (Cron)

Recommended cadence is once per day for both polling sync and webhook self-healing.

1. SSH to the production host and open the crontab for the deploy user (the one that owns the app files):

```bash
crontab -e
```

2. Add these entries (adjust paths if your deploy path differs):

```cron
# Daily polling sync at 02:15
15 2 * * * flock -n /tmp/budget-bank-sync.lock -c 'cd /var/www/api/current && php bin/console app:bank:sync --env=prod --no-debug >> /var/www/api/shared/var/log/bank-sync.log 2>&1'

# Daily webhook refresh at 02:45
45 2 * * * flock -n /tmp/budget-bank-webhooks.lock -c 'cd /var/www/api/current && php bin/console app:bank:webhooks:refresh --env=prod --no-debug >> /var/www/api/shared/var/log/bank-webhooks-refresh.log 2>&1'
```

3. Validate that required env vars exist in production shared dotenv or system env:

- `WEBHOOK_BASE_URL`
- `WISE_API_KEY`
- `MONOBANK_API_KEY`

Remote one-off runs are also available through composer/deployer:

```bash
composer bank:webhooks:refresh:remote
composer bank:sync:remote
composer bank:maintenance:remote
composer bank:logs:remote
```

---

## Setting Up SonarQube

SonarQube is a tool for continuous inspection of code quality to perform automatic reviews with static analysis. Follow the steps below to set it up.

### Using Docker

1. **Pull the Docker images**:
   ```bash
   docker pull sonarqube:community
   docker pull postgres
   ```

2. **Start the PostgreSQL container**:
    ```bash
   docker run -d --name sonarqube-postgres \
    -e POSTGRES_USER=sonar \
    -e POSTGRES_PASSWORD=sonar \
    -e POSTGRES_DB=sonarqube \
    -p 5432:5432 \
    postgres
   ```

3. **Start the SonarQube container**:
    ```bash
   docker run -d --name sonarqube \
    -p 9000:9000 \
    --link sonarqube-postgres:db \
    -e SONAR_JDBC_URL=jdbc:postgresql://db/sonarqube \
    -e SONAR_JDBC_USERNAME=sonar \
    -e SONAR_JDBC_PASSWORD=sonar \
    sonarqube:community
   ```

SonarQube will be available at [http://localhost:9000](http://localhost:9000) with the following credentials:
- **Username**: `admin`
- **Password**: `admin`

### Using Docker Compose

Alternatively, you can use the predefined configuration in the `docker-compose-sonarqube.yml` file. To start the services, run:

```bash
docker-compose -f docker-compose-sonarqube.yml up -d
```

---

## Running SonarQube Analysis

After setting up SonarQube, you can run the Sonar Scanner to analyze the code:

```bash
sonar-scanner
```

Once the analysis is complete, check the SonarQube dashboard at [http://localhost:9000](http://localhost:9000) to view the results.

## Tests performance
Running
```bash
cl -e test && composer test
```

**Time: 03:43.462, Memory: 752.50 MB**

Adding the following code to `BaseApiTest.php`:
```php
$this->em->clear();
self::ensureKernelShutdown();
gc_enable();
gc_collect_cycles();
```
Gives the following result:

**Time: 02:13.314, Memory: 752.50 MB**

