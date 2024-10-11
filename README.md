
# Financial Tracker Application

This financial tracker application supports multiple currencies: EUR, USD, UAH, HUF, and BTC.
It allows users to manage various accounts, track debts, and categorize transactions efficiently.

## Table of Contents

1. [Entities](#entities)
2. [Redundant Tables](#redundant-tables)
3. [Running Tests](#running-tests)
4. [Setting Up SonarQube](#setting-up-sonarqube)
    - [Using Docker](#using-docker)
    - [Using Docker Compose](#using-docker-compose)
5. [Running SonarQube Analysis](#running-sonarqube-analysis)

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

## Redundant Tables

There are outdated tables such as **currency** and budget-related tables that should be considered for removal.

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

