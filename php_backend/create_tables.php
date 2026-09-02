<?php
// Resets and creates all database tables used by the application.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SchemaCatalog.php';
require_once __DIR__ . '/models/Transaction.php';
require_once __DIR__ . '/models/Log.php';

$db = Database::getConnection();

if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
    throw new RuntimeException('Database creation requires PostgreSQL. Set DB_DSN to a pgsql DSN or configure DB_HOST/DB_PORT/DB_NAME.');
}

$db->beginTransaction();
$tables = array_reverse(array_keys(SchemaCatalog::tables()));
foreach ($tables as $table) {
    $db->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $table) . '" CASCADE');
}
foreach (SchemaCatalog::createStatements() as $statement) {
    $db->exec($statement);
}

// Seed default segments and categories on a fresh database
$result = $db->query('SELECT COUNT(*) FROM segments');
if ($result->fetchColumn() == 0) {
    $defaultSegments = [
        [
            'name' => 'Fixed Commitments',
            'description' => 'Obligations that are unavoidable and cannot be changed in the short term without significant disruption or penalty. These represent your baseline cost of living.',
            'categories' => [
                ['name' => 'Mortgage / Rent', 'description' => 'Regular housing payments that provide your primary residence; contractual and immovable in the near term.'],
                ['name' => 'Utilities', 'description' => 'Gas, electricity, and water bills that supply essential household services; non-negotiable for daily living.'],
                ['name' => 'Council Tax', 'description' => 'Statutory local taxation payable to the council; mandatory with no discretion.'],
                ['name' => 'Insurance', 'description' => 'Home, car, health, and life policies that provide financial protection and are often legal or contractual necessities.'],
                ['name' => 'Telecoms', 'description' => 'Broadband and mobile contracts underpinning communication and work; typically fixed term with limited flexibility.'],
                ['name' => 'Subscriptions (Non-discretionary)', 'description' => 'Core statutory or quasi-statutory payments such as TV licences or mandatory service charges.'],
            ]
        ],
        [
            'name' => 'Essential Variables',
            'description' => 'Necessary costs that fluctuate and can be trimmed through management, yet remain core to maintaining normal living standards.',
            'categories' => [
                ['name' => 'Supermarkets / Groceries', 'description' => 'Food, drink, and household consumables; unavoidable but controllable through choices and planning.'],
                ['name' => 'Fuel / Transport', 'description' => 'Petrol, road tolls, rail tickets, and bus fares; essential for mobility, with scope to optimise routes and providers.'],
                ['name' => 'Healthcare', 'description' => 'Prescriptions, dentistry, opticians, and private GP visits; required for health maintenance with variable costs.'],
                ['name' => 'Childcare / School Costs', 'description' => 'Nursery fees, clubs, trips, and education-related charges; essential where applicable to family circumstances.'],
                ['name' => 'Essential Household', 'description' => 'Cleaning supplies, small repairs, and maintenance items necessary to keep the home functioning properly.'],
            ]
        ],
        [
            'name' => 'Discretionary Spend',
            'description' => 'Lifestyle choices that enhance quality of life but are not strictly necessary; reducible or removable under pressure.',
            'categories' => [
                ['name' => 'Restaurants / Pubs / Takeaways', 'description' => 'Spending on dining out, socialising, and convenience food; discretionary and easily curtailed.'],
                ['name' => 'Entertainment', 'description' => 'Streaming, cinema, theatre, concerts, and media; enriching but optional consumption.'],
                ['name' => 'Holidays / Travel', 'description' => 'Flights, hotels, and leisure trips; enjoyable, non-essential, and highly flexible to reduce.'],
                ['name' => 'Shopping', 'description' => 'Clothing, technology, homewares, and other non-essential retail purchases; often deferrable or avoidable.'],
                ['name' => 'Hobbies', 'description' => 'Personal interests such as books, gaming, photography, and sports equipment; fulfilling yet discretionary.'],
                ['name' => 'Subscriptions (Discretionary)', 'description' => 'Services like Netflix, Spotify, and gym memberships; cancellable without affecting essential living.'],
            ]
        ],
        [
            'name' => 'Financial Costs',
            'description' => 'Charges linked to borrowing, banking, and debt servicing that erode disposable income without adding utility.',
            'categories' => [
                ['name' => 'Loan Repayments', 'description' => 'Scheduled payments covering principal and interest on personal loans or other borrowings.'],
                ['name' => 'Credit Card Payments', 'description' => 'Repayments on outstanding balances; important to distinguish principal reduction from pure interest.'],
                ['name' => 'Overdraft Charges', 'description' => 'Fees for using overdraft facilities; generally expensive and best avoided.'],
                ['name' => 'Bank Fees', 'description' => 'Monthly account charges, penalty fees, and administrative costs imposed by the bank.'],
                ['name' => 'Interest Charges', 'description' => 'Additional costs on debt, including credit card interest, loan interest, and late payment penalties.'],
            ]
        ],
        [
            'name' => 'Income & Adjustments',
            'description' => 'All inflows and offsets, distinguishing true earnings from corrections and transfers to understand net position.',
            'categories' => [
                ['name' => 'Salary / Wages', 'description' => 'Regular employment income; predictable, recurring, and typically the largest inflow.'],
                ['name' => 'Benefits / Allowances', 'description' => 'State support such as child benefit, tax credits, or housing allowances contributing to essential income.'],
                ['name' => 'Refunds / Rebates', 'description' => 'Returned purchases, tax adjustments, and reimbursements; offsets prior spend rather than new earnings.'],
                ['name' => 'Transfers In', 'description' => 'Funds moved from other personal accounts; label clearly to avoid inflating income figures.'],
            ]
        ]
    ];

    $segStmt = $db->prepare('INSERT INTO segments (name, description) VALUES (:name, :description)');
    $catStmt = $db->prepare('INSERT INTO categories (name, description, segment_id) VALUES (:name, :description, :segment_id)');
    $linkStmt = $db->prepare('INSERT INTO segment_categories (segment_id, category_id) VALUES (:segment_id, :category_id)');


    foreach ($defaultSegments as $seg) {
        $segStmt->execute(['name' => $seg['name'], 'description' => $seg['description']]);
        $segmentId = (int)$db->lastInsertId();
        foreach ($seg['categories'] as $cat) {
            $catStmt->execute([
                'name' => $cat['name'],
                'description' => $cat['description'],
                'segment_id' => $segmentId
            ]);

            $categoryId = (int)$db->lastInsertId();
            $linkStmt->execute([
                'segment_id' => $segmentId,
                'category_id' => $categoryId
            ]);

        }
    }
}

$db->commit();
echo "Database tables created.\n";
?>
