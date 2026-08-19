<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';
require_login();
update_overdue($pdo);

$user = current_user();
$page = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard', 'books', 'categories', 'members', 'issues', 'return', 'overdue', 'reports', 'users', 'activity_logs', 'database_backup'];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

// Restrict Users page to admins only
if (in_array($page, ['users', 'activity_logs', 'database_backup'], true) && ($user['role'] ?? '') !== 'admin') {
    flash('error', 'Access restricted to administrators only.');
    redirect('app.php?page=dashboard');
}

// -------------------------------------------------------------
// POST Actions Handler (Transactional & CSRF Protected)
// -------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $returnPage = $_POST['return_page'] ?? $page;

    if ($action === 'download_database_backup') {
        require_admin();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $filename = DB_NAME . '-' . date('Y-m-d-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        echo "-- Leaf & Lore database backup\n-- Generated: " . date(DATE_ATOM) . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $table) {
            $safeTable = str_replace('`', '``', (string)$table);
            $create = $pdo->query("SHOW CREATE TABLE `{$safeTable}`")->fetch(PDO::FETCH_NUM);
            echo "DROP TABLE IF EXISTS `{$safeTable}`;\n{$create[1]};\n\n";
            $rows = $pdo->query("SELECT * FROM `{$safeTable}`");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                $values = array_map(static fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value), array_values($row));
                echo "INSERT INTO `{$safeTable}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            }
            echo "\n";
        }
        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit;
    }

    try {
        // --- 1. SAVE BOOK (ADD / EDIT) ---
        if ($action === 'save_book') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $author = trim($_POST['author'] ?? '');
            $isbn = trim($_POST['isbn'] ?? '');
            $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $publisher = trim($_POST['publisher'] ?? '');
            $publishedYear = !empty($_POST['published_year']) ? (int)$_POST['published_year'] : null;
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $rackNo = trim($_POST['rack_no'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (empty($title) || empty($author)) {
                throw new Exception('Book title and author are required.');
            }

            if ($id > 0) {
                // Determine currently issued copies to calculate available_quantity correctly
                $stmt = $pdo->prepare('SELECT quantity, available_quantity FROM books WHERE id = ?');
                $stmt->execute([$id]);
                $current = $stmt->fetch();
                $issuedCount = $current ? max(0, $current['quantity'] - $current['available_quantity']) : 0;
                $newAvailable = max(0, $quantity - $issuedCount);

                $sql = 'UPDATE books SET title = ?, author = ?, isbn = ?, category_id = ?, publisher = ?, published_year = ?, quantity = ?, available_quantity = ?, rack_no = ?, description = ? WHERE id = ?';
                $pdo->prepare($sql)->execute([$title, $author, $isbn ?: null, $categoryId, $publisher ?: null, $publishedYear, $quantity, $newAvailable, $rackNo ?: null, $description ?: null, $id]);
                flash('success', "Book '{$title}' updated successfully.");
            } else {
                $sql = 'INSERT INTO books (title, author, isbn, category_id, publisher, published_year, quantity, available_quantity, rack_no, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $pdo->prepare($sql)->execute([$title, $author, $isbn ?: null, $categoryId, $publisher ?: null, $publishedYear, $quantity, $quantity, $rackNo ?: null, $description ?: null]);
                flash('success', "New book '{$title}' added to inventory.");
            }
        }

        // --- 2. DELETE BOOK ---
        elseif ($action === 'delete_book') {
            $id = (int)($_POST['id'] ?? 0);
            // Check if book has active issues
            $chk = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE book_id = ? AND status IN ('issued', 'overdue')");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception('Cannot delete book: Copies of this book are currently borrowed.');
            }
            $pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$id]);
            flash('success', 'Book removed from library.');
        }

        // --- 3. SAVE CATEGORY ---
        elseif ($action === 'save_category') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');

            if (empty($name)) throw new Exception('Category name is required.');

            if ($id > 0) {
                $pdo->prepare('UPDATE categories SET category_name = ?, description = ? WHERE id = ?')->execute([$name, $desc ?: null, $id]);
                flash('success', 'Category updated successfully.');
            } else {
                $pdo->prepare('INSERT INTO categories (category_name, description) VALUES (?, ?)')->execute([$name, $desc ?: null]);
                flash('success', 'New category created.');
            }
        }

        // --- 4. DELETE CATEGORY ---
        elseif ($action === 'delete_category') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('success', 'Category deleted.');
        }

        // --- 5. SAVE MEMBER ---
        elseif ($action === 'save_member') {
            $id = (int)($_POST['id'] ?? 0);
            $memberNo = trim($_POST['member_no'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if (empty($fullName) || empty($email)) throw new Exception('Full name and email are required.');

            if ($id > 0) {
                $pdo->prepare('UPDATE members SET member_no = ?, full_name = ?, email = ?, phone = ?, address = ?, status = ? WHERE id = ?')
                    ->execute([$memberNo, $fullName, $email, $phone, $address, $status, $id]);
                flash('success', 'Member record updated.');
            } else {
                if (empty($memberNo)) {
                    $memberNo = 'MEM-' . date('ym') . rand(100, 999);
                }
                $pdo->prepare('INSERT INTO members (member_no, full_name, email, phone, address, status) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$memberNo, $fullName, $email, $phone, $address, $status]);
                flash('success', 'New library member registered successfully.');
            }
        }

        // --- 6. DELETE MEMBER ---
        elseif ($action === 'delete_member') {
            $id = (int)($_POST['id'] ?? 0);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM book_issues WHERE member_id = ? AND status IN ('issued', 'overdue')");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception('Cannot delete member: This member currently has unreturned books.');
            }
            $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
            flash('success', 'Member removed.');
        }

        // --- 7. ISSUE BOOK (LENDING) ---
        elseif ($action === 'issue_book') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $bookId = (int)($_POST['book_id'] ?? 0);
            $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
            $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
            $remarks = trim($_POST['remarks'] ?? '');

            if (!$memberId || !$bookId) throw new Exception('Please select a member and a book.');
            if ($dueDate < $issueDate) throw new Exception('Due date cannot be earlier than issue date.');

            $pdo->beginTransaction();

            // Lock book row to prevent race conditions
            $stmt = $pdo->prepare('SELECT title, available_quantity FROM books WHERE id = ? FOR UPDATE');
            $stmt->execute([$bookId]);
            $book = $stmt->fetch();

            if (!$book || (int)$book['available_quantity'] < 1) {
                throw new Exception("Sorry, '{$book['title']}' is currently out of stock.");
            }

            // Create issue record
            $sql = 'INSERT INTO book_issues (member_id, book_id, issued_by, issue_date, due_date, status, remarks) VALUES (?, ?, ?, ?, ?, "issued", ?)';
            $pdo->prepare($sql)->execute([$memberId, $bookId, $user['id'] ?? null, $issueDate, $dueDate, $remarks ?: null]);

            // Decrement available copies
            $pdo->prepare('UPDATE books SET available_quantity = available_quantity - 1 WHERE id = ?')->execute([$bookId]);

            $pdo->commit();
            flash('success', "Book '{$book['title']}' has been issued successfully.");
        }

        // --- 8. RETURN BOOK ---
        elseif ($action === 'return_book') {
            $issueId = (int)($_POST['issue_id'] ?? 0);
            $returnDate = $_POST['return_date'] ?? date('Y-m-d');
            $fineCollected = (float)($_POST['fine_collected'] ?? 0.00);
            $remarks = trim($_POST['remarks'] ?? '');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT bi.*, b.id AS book_id, b.title FROM book_issues bi JOIN books b ON b.id = bi.book_id WHERE bi.id = ? AND bi.return_date IS NULL FOR UPDATE');
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch();

            if (!$issue) throw new Exception('Active issue record not found or already returned.');

            // Calculate overdue days
            $due = new DateTime($issue['due_date']);
            $ret = new DateTime($returnDate);
            $overdueDays = $ret > $due ? (int)$due->diff($ret)->format('%a') : 0;
            $calculatedFine = $overdueDays * FINE_PER_DAY;

            // Mark issue as returned
            $pdo->prepare('UPDATE book_issues SET return_date = ?, status = "returned", fine = ? WHERE id = ?')
                ->execute([$returnDate, $calculatedFine, $issueId]);

            // Create return audit log
            $pdo->prepare('INSERT INTO book_returns (issue_id, received_by, return_date, overdue_days, fine_collected, remarks) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$issueId, $user['id'] ?? null, $returnDate, $overdueDays, $fineCollected, $remarks ?: null]);

            // Restock book quantity
            $pdo->prepare('UPDATE books SET available_quantity = available_quantity + 1 WHERE id = ?')->execute([$issue['book_id']]);

            $pdo->commit();
            flash('success', "Book '{$issue['title']}' returned successfully." . ($overdueDays > 0 ? " (Overdue: {$overdueDays} days | Fine: Rs. " . number_format($fineCollected, 2) . ")" : ''));
        }

        // --- 9. SAVE SYSTEM USER (ADMIN ONLY) ---
        elseif ($action === 'save_user') {
            require_admin();
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'librarian';
            $status = $_POST['status'] ?? 'active';
            $password = $_POST['password'] ?? '';

            if (empty($name) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please provide a valid name and email address.');
            }

            if ($id > 0) {
                if (!empty($password)) {
                    if (strlen($password) < 6) throw new Exception('Password must have at least 6 characters.');
                    $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ?, password = ? WHERE id = ?')
                        ->execute([$name, $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?')
                        ->execute([$name, $email, $role, $status, $id]);
                }
                flash('success', "User '{$name}' updated.");
            } else {
                if (strlen($password) < 6) throw new Exception('Password must have at least 6 characters.');
                $pdo->prepare('INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
                flash('success', "New user '{$name}' created.");
            }
        }

        // --- 10. DELETE USER ---
        elseif ($action === 'delete_user') {
            require_admin();
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$user['id']) {
                throw new Exception('You cannot delete your own logged-in account.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'User removed.');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }

    redirect('app.php?page=' . urlencode($returnPage));
}

// -------------------------------------------------------------
// Data Fetching & Calculations
// -------------------------------------------------------------
$overdueCount = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE status = 'overdue' AND return_date IS NULL")->fetchColumn();
$flash = get_flash();

$pageTitles = [
    'dashboard'  => 'Dashboard & Overview',
    'books'      => 'Books Inventory Management',
    'categories' => 'Book Categories',
    'members'    => 'Library Members',
    'issues'     => 'Issue / Borrow Books',
    'return'     => 'Return Books & Fines',
    'overdue'    => 'Overdue Books Tracker',
    'reports'    => 'Reports & Analytics',
    'users'      => 'System Users & Roles',
    'activity_logs' => 'Activity Logs',
    'database_backup' => 'Database Backup',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?=e($pageTitles[$page] ?? 'Dashboard')?> | Library Management System</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Modern Theme CSS -->
    <link rel="stylesheet" href="/css/modern-theme.css">
</head>
<body>

<div class="app-wrapper">
    <!-- Mobile Backdrop -->
    <div class="sidebar-backdrop"></div>

    <!-- =========================================================
         SIDEBAR NAVIGATION
         ========================================================= -->
    <aside class="app-sidebar">
        <a href="app.php?page=dashboard" class="sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-book-half"></i>
            </div>
            <div>
                <div class="brand-title">Leaf &amp; Lore</div>
                <div class="brand-subtitle">Library System</div>
            </div>
        </a>

        <div class="sidebar-nav">
            <div class="nav-section-label">Main Menu</div>
            <a href="app.php?page=dashboard" class="nav-link <?=$page==='dashboard'?'active':''?>">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <div class="nav-section-label">Catalog &amp; Circulation</div>
            <a href="app.php?page=books" class="nav-link <?=$page==='books'?'active':''?>">
                <i class="bi bi-journal-bookmark-fill"></i>
                <span>Books Management</span>
            </a>
            <a href="app.php?page=categories" class="nav-link <?=$page==='categories'?'active':''?>">
                <i class="bi bi-tags-fill"></i>
                <span>Categories</span>
            </a>
            <a href="app.php?page=members" class="nav-link <?=$page==='members'?'active':''?>">
                <i class="bi bi-people-fill"></i>
                <span>Members</span>
            </a>

            <div class="nav-section-label">Lending Operations</div>
            <a href="app.php?page=issues" class="nav-link <?=$page==='issues'?'active':''?>">
                <i class="bi bi-arrow-up-right-circle-fill"></i>
                <span>Issue Books</span>
            </a>
            <a href="app.php?page=return" class="nav-link <?=$page==='return'?'active':''?>">
                <i class="bi bi-arrow-down-left-circle-fill"></i>
                <span>Return Books</span>
            </a>
            <a href="app.php?page=overdue" class="nav-link <?=$page==='overdue'?'active':''?> d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <span>Overdue Books</span>
                </div>
                <?php if ($overdueCount > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?=$overdueCount?></span>
                <?php endif; ?>
            </a>

            <div class="nav-section-label">Administration</div>
            <a href="app.php?page=reports" class="nav-link <?=$page==='reports'?'active':''?>">
                <i class="bi bi-file-earmark-bar-graph-fill"></i>
                <span>Reports</span>
            </a>
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="app.php?page=users" class="nav-link <?=$page==='users'?'active':''?>">
                    <i class="bi bi-person-gear"></i>
                    <span>User Management</span>
                </a>
                <div class="nav-section-label">Admin Tools</div>
                <a href="app.php?page=activity_logs" class="nav-link <?=$page==='activity_logs'?'active':''?>">
                    <i class="bi bi-journal-text"></i><span>Activity Logs</span>
                </a>
                <a href="app.php?page=database_backup" class="nav-link <?=$page==='database_backup'?'active':''?>">
                    <i class="bi bi-cloud-arrow-down"></i><span>Database Backup</span>
                </a>
            <?php endif; ?>
        </div>

    </aside>

    <!-- =========================================================
         MAIN APPLICATION AREA
         ========================================================= -->
    <div class="app-main">
        <!-- Top Navbar -->
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light d-lg-none" id="sidebarToggle">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div>
                    <h1 class="page-heading"><?=e($pageTitles[$page] ?? 'Dashboard')?></h1>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="text-end d-none d-sm-block">
                    <div class="small fw-semibold text-dark"><?=date('l, d M Y')?></div>
                    <div class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-geo-alt-fill text-danger"></i> Main Library</div>
                </div>
                <div class="dropdown">
                    <button class="topbar-account dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="topbar-account-avatar"><?=strtoupper(substr($user['name'] ?? 'U', 0, 1))?></span>
                        <span class="topbar-account-copy d-none d-md-block">
                            <strong><?=e($user['name'] ?? 'User')?></strong>
                            <small><?=e(ucfirst($user['role'] ?? 'Member'))?></small>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end account-menu shadow border-0">
                        <li class="px-3 py-2">
                            <strong class="d-block small"><?=e($user['name'] ?? 'User')?></strong>
                            <small class="text-muted"><?=e($user['email'])?></small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Flash Toast / Alert -->
        <div class="px-4 pt-3">
            <?php if ($flash): ?>
                <div class="alert alert-<?=$flash['type']==='success'?'success':($flash['type']==='error'?'danger':'info')?> alert-dismissible fade show alert-dismissible-auto shadow-sm" role="alert">
                    <i class="bi bi-<?=$flash['type']==='success'?'check-circle-fill':($flash['type']==='error'?'exclamation-circle-fill':'info-circle-fill')?> me-2"></i>
                    <strong><?=e($flash['message'])?></strong>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Page Content -->
        <main class="app-content">

        <!-- =======================================================
             MODULE 1: DASHBOARD
             ======================================================= -->
        <?php if ($page === 'dashboard'):
            $totalBooks = (int)$pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM books')->fetchColumn();
            $availableBooks = (int)$pdo->query('SELECT COALESCE(SUM(available_quantity), 0) FROM books')->fetchColumn();
            $issuedBooks = (int)$pdo->query("SELECT COUNT(*) FROM book_issues WHERE return_date IS NULL AND status IN ('issued', 'overdue')")->fetchColumn();
            $totalMembers = (int)$pdo->query('SELECT COUNT(*) FROM members WHERE status = "active"')->fetchColumn();
            $totalFines = (float)$pdo->query('SELECT COALESCE(SUM(fine_collected), 0) FROM book_returns')->fetchColumn();

            // Recent Borrow Transactions
            $recentStmt = $pdo->query("
                SELECT bi.*, m.full_name, m.member_no, b.title, b.isbn 
                FROM book_issues bi 
                JOIN members m ON m.id = bi.member_id 
                JOIN books b ON b.id = bi.book_id 
                ORDER BY bi.id DESC LIMIT 6
            ");
            $recentIssues = $recentStmt->fetchAll();

            // Category counts for Chart
            $catDist = $pdo->query('SELECT c.category_name, COUNT(b.id) as count FROM categories c LEFT JOIN books b ON b.category_id = c.id GROUP BY c.id ORDER BY count DESC LIMIT 5')->fetchAll();
            $catLabels = array_column($catDist, 'category_name');
            $catData = array_map('intval', array_column($catDist, 'count'));
        ?>

            <!-- Overdue Warning Alert if any -->
            <?php if ($overdueCount > 0): ?>
                <div class="alert alert-warning border-warning d-flex align-items-center justify-content-between p-3 rounded-4 shadow-sm mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi bi-exclamation-triangle-fill fs-3 text-warning"></i>
                        <div>
                            <strong class="d-block">Attention Required: <?=$overdueCount?> book loan<?=$overdueCount===1?' is':'s are'?> currently overdue!</strong>
                            <span class="small text-secondary">Borrowers have exceeded their return due dates. View overdue tracking to review and collect fines.</span>
                        </div>
                    </div>
                    <a href="app.php?page=overdue" class="btn btn-warning btn-sm fw-semibold rounded-pill px-3">View Overdue List</a>
                </div>
            <?php endif; ?>

            <!-- Metric KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Total Books</div>
                            <div class="stat-value"><?=$totalBooks?></div>
                            <small class="text-success"><i class="bi bi-book"></i> <?=$availableBooks?> Available</small>
                        </div>
                        <div class="stat-icon primary">
                            <i class="bi bi-journals"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Issued Books</div>
                            <div class="stat-value text-primary"><?=$issuedBooks?></div>
                            <small class="text-muted"><i class="bi bi-clock-history"></i> Currently with members</small>
                        </div>
                        <div class="stat-icon warning">
                            <i class="bi bi-arrow-up-right-circle"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Active Members</div>
                            <div class="stat-value"><?=$totalMembers?></div>
                            <small class="text-muted"><i class="bi bi-person-check"></i> Registered borrowers</small>
                        </div>
                        <div class="stat-icon success">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card">
                        <div>
                            <div class="stat-label">Fines Collected</div>
                            <div class="stat-value text-success"><?=format_currency($totalFines)?></div>
                            <small class="text-danger"><i class="bi bi-exclamation-circle"></i> <?=$overdueCount?> Overdue loans</small>
                        </div>
                        <div class="stat-icon info">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="row g-4 mb-4">
                <!-- 1. Borrowing Trends Line Chart -->
                <div class="col-lg-7">
                    <div class="card-modern p-4 h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">Borrowing Trends</h5>
                                <small class="text-muted">Monthly circulation activity</small>
                            </div>
                            <span class="badge bg-primary-subtle text-primary border px-3 py-1.5 rounded-pill fw-semibold">
                                <i class="bi bi-graph-up-arrow me-1"></i> Active
                            </span>
                        </div>
                        <div class="flex-grow-1" style="min-height: 280px; position: relative;">
                            <canvas id="borrowingTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 2. Book Categories Doughnut Chart -->
                <div class="col-lg-5">
                    <div class="card-modern p-4 h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark">Book Categories</h5>
                                <small class="text-muted">Inventory breakdown by genre</small>
                            </div>
                            <span class="badge bg-light text-dark border px-2.5 py-1 rounded-pill">
                                <?=count($catDist)?> Categories
                            </span>
                        </div>

                        <!-- Doughnut Chart Container with Centered Metric -->
                        <div class="position-relative d-flex justify-content-center align-items-center my-2" style="height: 200px;">
                            <canvas id="categoryDistributionChart"></canvas>
                            <div class="position-absolute text-center" style="pointer-events: none;">
                                <div class="display-6 fw-bold text-dark lh-1 mb-1"><?=$totalBooks?></div>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.06em;">Total Books</small>
                            </div>
                        </div>

                        <!-- Sleek Category Legend & Share List -->
                        <div class="mt-3 pt-3 border-top flex-grow-1">
                            <div class="d-flex flex-column gap-2">
                                <?php 
                                $palette = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#06b6d4'];
                                foreach ($catDist as $idx => $cd): 
                                    $color = $palette[$idx % count($palette)];
                                    $pct = $totalBooks > 0 ? round(($cd['count'] / $totalBooks) * 100) : 0;
                                ?>
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                            <span class="rounded-circle d-inline-block flex-shrink-0" style="width: 10px; height: 10px; background-color: <?=$color?>; box-shadow: 0 0 0 2px rgba(0,0,0,0.05);"></span>
                                            <span class="small fw-medium text-dark text-truncate" title="<?=e($cd['category_name'])?>"><?=e($cd['category_name'])?></span>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                            <span class="badge bg-light text-secondary border px-2 py-1 small fw-semibold"><?=$cd['count']?> book<?=$cd['count']===1?'':'s'?></span>
                                            <small class="text-muted fw-semibold" style="width: 32px; text-align: right; font-size: 0.75rem;"><?=$pct?>%</small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Table -->
            <div class="card-modern">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0">Recent Library Transactions</h5>
                        <small class="text-muted">Latest lending and return events</small>
                    </div>
                    <a href="app.php?page=reports" class="btn btn-sm btn-outline-primary rounded-pill px-3">View Full History</a>
                </div>
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Fine</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentIssues): ?>
                                <tr><td colspan="6" class="text-center py-4 text-muted">No transactions found.</td></tr>
                            <?php else: foreach ($recentIssues as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?=e($row['full_name'])?></div>
                                        <small class="text-muted"><?=e($row['member_no'])?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?=e($row['title'])?></div>
                                        <small class="text-muted">ISBN: <?=e($row['isbn'] ?? 'N/A')?></small>
                                    </td>
                                    <td><?=e($row['issue_date'])?></td>
                                    <td><?=e($row['due_date'])?></td>
                                    <td>
                                        <span class="badge-status <?=$row['status']?>">
                                            <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> <?=ucfirst($row['status'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((float)$row['fine'] > 0): ?>
                                            <span class="text-danger fw-semibold"><?=format_currency($row['fine'])?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Quick Actions -->
            <section class="dashboard-quick-actions mt-4" aria-labelledby="quick-actions-title">
                <div class="mb-3">
                    <h5 class="fw-bold mb-1" id="quick-actions-title">Quick Actions</h5>
                    <small class="text-muted">Jump directly to common library management tasks</small>
                </div>
                <div class="quick-actions-grid">
                    <a href="app.php?page=books" class="quick-action-card">
                        <i class="bi bi-journal-plus"></i><strong>Add New Book</strong><small>Add a new title to the library catalog</small>
                    </a>
                    <a href="app.php?page=categories" class="quick-action-card">
                        <i class="bi bi-tags"></i><strong>Book Categories</strong><small>Create and organize catalog categories</small>
                    </a>
                    <a href="app.php?page=members" class="quick-action-card">
                        <i class="bi bi-person-plus"></i><strong>Manage Members</strong><small>Register, edit, and review library members</small>
                    </a>
                    <a href="app.php?page=issues" class="quick-action-card">
                        <i class="bi bi-bookmark-plus"></i><strong>Issue a Book</strong><small>Record a new book borrowing</small>
                    </a>
                    <a href="app.php?page=return" class="quick-action-card">
                        <i class="bi bi-bookmark-check"></i><strong>Return a Book</strong><small>Process returns and calculate fines</small>
                    </a>
                    <a href="app.php?page=overdue" class="quick-action-card">
                        <i class="bi bi-calendar2-x"></i><strong>Overdue Books</strong><small>Review overdue loans and outstanding fines</small>
                    </a>
                    <a href="app.php?page=reports" class="quick-action-card">
                        <i class="bi bi-file-earmark-bar-graph"></i><strong>Reports &amp; Analytics</strong><small>View circulation and library reports</small>
                    </a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?>
                        <a href="app.php?page=users" class="quick-action-card">
                            <i class="bi bi-person-gear"></i><strong>Manage Users</strong><small>Control system users, roles, and access</small>
                        </a>
                        <a href="app.php?page=activity_logs" class="quick-action-card">
                            <i class="bi bi-journal-text"></i><strong>Activity Logs</strong><small>Review recent system and circulation activity</small>
                        </a>
                        <a href="app.php?page=database_backup" class="quick-action-card">
                            <i class="bi bi-database-down"></i><strong>Database Backup</strong><small>Generate and download a complete SQL backup</small>
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                const monthlyTrends = {
                    labels: ['3 Months Ago', '2 Months Ago', 'Last Month', 'This Month'],
                    data: [8, 14, 18, <?=$issuedBooks + 2?>]
                };
                const categoryDist = {
                    labels: <?=json_encode($catLabels)?>,
                    data: <?=json_encode($catData)?>
                };
                initDashboardCharts(monthlyTrends, categoryDist);
            });
            </script>

        <!-- =======================================================
             MODULE 2: BOOKS MANAGEMENT
             ======================================================= -->
        <?php elseif ($page === 'books'):
            $search = trim($_GET['q'] ?? '');
            $categoryFilter = !empty($_GET['category']) ? (int)$_GET['category'] : 0;

            $sql = 'SELECT b.*, c.category_name FROM books b LEFT JOIN categories c ON c.id = b.category_id WHERE 1=1';
            $params = [];

            if ($search !== '') {
                $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.rack_no LIKE ?)';
                $like = "%$search%";
                $params = array_merge($params, [$like, $like, $like, $like]);
            }
            if ($categoryFilter > 0) {
                $sql .= ' AND b.category_id = ?';
                $params[] = $categoryFilter;
            }
            $sql .= ' ORDER BY b.id DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $books = $stmt->fetchAll();

            $categories = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();

            // Check if editing
            $editBook = null;
            if (isset($_GET['edit'])) {
                $s = $pdo->prepare('SELECT * FROM books WHERE id = ?');
                $s->execute([(int)$_GET['edit']]);
                $editBook = $s->fetch();
            }
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1">Books Catalog</h4>
                    <p class="text-muted small mb-0">Manage library books, inventory counts, and shelf locations.</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
                        <i class="bi bi-plus-lg me-1"></i> Add New Book
                    </button>
                </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div class="card-modern p-3 mb-4">
                <form method="get" class="row g-2 align-items-center">
                    <input type="hidden" name="page" value="books">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" name="q" value="<?=e($search)?>" class="form-control border-start-0" placeholder="Search by title, author, ISBN, or rack...">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <select name="category" class="form-select" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?=$cat['id']?>" <?=$categoryFilter===$cat['id']?'selected':''?>><?=e($cat['category_name'])?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-secondary w-100">Filter</button>
                        <?php if ($search || $categoryFilter): ?>
                            <a href="app.php?page=books" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Books Table -->
            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Book Title &amp; Details</th>
                                <th>Category</th>
                                <th>ISBN</th>
                                <th>Location</th>
                                <th>Availability</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$books): ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">No books found matching your search.</td></tr>
                            <?php else: foreach ($books as $b): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="brand-icon" style="width: 38px; height: 38px; font-size: 1rem;">
                                                <i class="bi bi-book"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?=e($b['title'])?></div>
                                                <small class="text-muted">By <?=e($b['author'])?> <?=!empty($b['published_year'])?'('.$b['published_year'].')':''?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?=e($b['category_name'] ?? 'Uncategorized')?></span></td>
                                    <td><code><?=e($b['isbn'] ?? '—')?></code></td>
                                    <td><small class="text-secondary"><i class="bi bi-geo-alt"></i> <?=e($b['rack_no'] ?? 'Unassigned')?></small></td>
                                    <td>
                                        <?php if ($b['available_quantity'] > 0): ?>
                                            <span class="badge-status in-stock">
                                                <i class="bi bi-check2"></i> <?=$b['available_quantity']?> / <?=$b['quantity']?> Available
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status out-of-stock">
                                                <i class="bi bi-x"></i> 0 / <?=$b['quantity']?> Out of stock
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="app.php?page=books&edit=<?=$b['id']?>" class="btn btn-outline-primary" title="Edit Book">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this book?');">
                                                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                                <input type="hidden" name="action" value="delete_book">
                                                <input type="hidden" name="id" value="<?=$b['id']?>">
                                                <input type="hidden" name="return_page" value="books">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete Book">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add / Edit Book Modal -->
            <div class="modal fade <?=$editBook?'show d-block':''?>" id="bookModal" tabindex="-1" style="<?=$editBook?'background: rgba(0,0,0,0.5);':''?>">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="app.php?page=books">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="save_book">
                            <input type="hidden" name="return_page" value="books">
                            <input type="hidden" name="id" value="<?=e((string)($editBook['id'] ?? ''))?>">

                            <div class="modal-header">
                                <h5 class="modal-title fw-bold">
                                    <i class="bi bi-journal-bookmark-fill text-primary me-2"></i>
                                    <?=$editBook ? 'Edit Book Details' : 'Add New Book to Inventory'?>
                                </h5>
                                <?php if ($editBook): ?>
                                    <a href="app.php?page=books" class="btn-close"></a>
                                <?php else: ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                <?php endif; ?>
                            </div>

                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label fw-semibold small">Book Title *</label>
                                        <input type="text" name="title" class="form-control" placeholder="e.g. Clean Code" required value="<?=e($editBook['title']??'')?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small">ISBN / Barcode</label>
                                        <input type="text" name="isbn" class="form-control" placeholder="e.g. 978-0132350884" value="<?=e($editBook['isbn']??'')?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">Author(s) *</label>
                                        <input type="text" name="author" class="form-control" placeholder="e.g. Robert C. Martin" required value="<?=e($editBook['author']??'')?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">Category</label>
                                        <select name="category_id" class="form-select">
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?=$cat['id']?>" <?=($editBook['category_id']??'')==$cat['id']?'selected':''?>><?=e($cat['category_name'])?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold small">Publisher</label>
                                        <input type="text" name="publisher" class="form-control" placeholder="e.g. Prentice Hall" value="<?=e($editBook['publisher']??'')?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Published Year</label>
                                        <input type="number" name="published_year" class="form-control" placeholder="2024" min="1800" max="<?=date('Y')+1?>" value="<?=e((string)($editBook['published_year']??''))?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold small">Total Copies *</label>
                                        <input type="number" name="quantity" class="form-control" min="1" required value="<?=e((string)($editBook['quantity']??1))?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold small">Shelf / Rack No</label>
                                        <input type="text" name="rack_no" class="form-control" placeholder="e.g. Rack A-1" value="<?=e($editBook['rack_no']??'')?>">
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label fw-semibold small">Description / Notes</label>
                                        <input type="text" name="description" class="form-control" placeholder="Short description or summary" value="<?=e($editBook['description']??'')?>">
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <?php if ($editBook): ?>
                                    <a href="app.php?page=books" class="btn btn-light">Cancel</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Book</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- =======================================================
             MODULE 3: CATEGORIES
             ======================================================= -->
        <?php elseif ($page === 'categories'):
            $categories = $pdo->query('
                SELECT c.*, COUNT(b.id) AS book_count 
                FROM categories c 
                LEFT JOIN books b ON b.category_id = c.id 
                GROUP BY c.id 
                ORDER BY c.category_name
            ')->fetchAll();

            $editCat = null;
            if (isset($_GET['edit'])) {
                $s = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
                $s->execute([(int)$_GET['edit']]);
                $editCat = $s->fetch();
            }
        ?>

            <div class="row g-4">
                <!-- Add / Edit Category Form -->
                <div class="col-lg-4">
                    <div class="card-modern p-4">
                        <h5 class="fw-bold mb-3">
                            <i class="bi bi-tag-fill text-primary me-2"></i>
                            <?=$editCat ? 'Edit Category' : 'Add New Category'?>
                        </h5>
                        <form method="post" action="app.php?page=categories">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="return_page" value="categories">
                            <input type="hidden" name="id" value="<?=e((string)($editCat['id']??''))?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Category Name *</label>
                                <input type="text" name="category_name" class="form-control" placeholder="e.g. Artificial Intelligence" required value="<?=e($editCat['category_name']??'')?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Brief category summary..."><?=e($editCat['description']??'')?></textarea>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1 fw-semibold">Save Category</button>
                                <?php if ($editCat): ?>
                                    <a href="app.php?page=categories" class="btn btn-outline-secondary">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Categories List -->
                <div class="col-lg-8">
                    <div class="card-modern">
                        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-0">Categories Directory</h5>
                                <small class="text-muted"><?=count($categories)?> book genres / categories defined</small>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Description</th>
                                        <th>Books Count</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$categories): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No categories created yet.</td></tr>
                                    <?php else: foreach ($categories as $c): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?=e($c['category_name'])?></div>
                                            </td>
                                            <td><small class="text-secondary"><?=e($c['description'] ?? '—')?></small></td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary border px-2 py-1">
                                                    <?=$c['book_count']?> books
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="app.php?page=categories&edit=<?=$c['id']?>" class="btn btn-outline-primary" title="Edit">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this category? Books linked to it will become uncategorized.');">
                                                        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                                        <input type="hidden" name="action" value="delete_category">
                                                        <input type="hidden" name="id" value="<?=$c['id']?>">
                                                        <input type="hidden" name="return_page" value="categories">
                                                        <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <!-- =======================================================
             MODULE 4: MEMBERS MANAGEMENT
             ======================================================= -->
        <?php elseif ($page === 'members'):
            $search = trim($_GET['q'] ?? '');
            $sql = '
                SELECT m.*, 
                    (SELECT COUNT(*) FROM book_issues WHERE member_id = m.id AND return_date IS NULL) AS active_loans 
                FROM members m 
                WHERE 1=1
            ';
            $params = [];
            if ($search !== '') {
                $sql .= ' AND (m.full_name LIKE ? OR m.member_no LIKE ? OR m.email LIKE ? OR m.phone LIKE ?)';
                $like = "%$search%";
                $params = [$like, $like, $like, $like];
            }
            $sql .= ' ORDER BY m.id DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $members = $stmt->fetchAll();

            $editMember = null;
            if (isset($_GET['edit'])) {
                $s = $pdo->prepare('SELECT * FROM members WHERE id = ?');
                $s->execute([(int)$_GET['edit']]);
                $editMember = $s->fetch();
            }
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1">Library Members</h4>
                    <p class="text-muted small mb-0">Registered borrowers, contact information, and membership status.</p>
                </div>
                <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#memberModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Register New Member
                </button>
            </div>

            <!-- Search Toolbar -->
            <div class="card-modern p-3 mb-4">
                <form method="get" class="row g-2 align-items-center">
                    <input type="hidden" name="page" value="members">
                    <div class="col-md-10">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" name="q" value="<?=e($search)?>" class="form-control border-start-0" placeholder="Search by member name, ID, email, or phone number...">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-secondary w-100">Search</button>
                        <?php if ($search): ?>
                            <a href="app.php?page=members" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Members Table -->
            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Member No &amp; Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Active Borrowings</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$members): ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted">No members found.</td></tr>
                            <?php else: foreach ($members as $m): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="user-avatar" style="width: 34px; height: 34px; font-size: 0.85rem;">
                                                <?=strtoupper(substr($m['full_name'], 0, 1))?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?=e($m['full_name'])?></div>
                                                <span class="badge bg-light text-primary border"><?=e($m['member_no'])?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?=e($m['email'])?></td>
                                    <td><?=e($m['phone'] ?? '—')?></td>
                                    <td>
                                        <?php if ($m['active_loans'] > 0): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1">
                                                <i class="bi bi-book"></i> <?=$m['active_loans']?> books borrowed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?=$m['status']?>">
                                            <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> <?=ucfirst($m['status'])?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="app.php?page=members&edit=<?=$m['id']?>" class="btn btn-outline-primary" title="Edit Member">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this member?');">
                                                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                                <input type="hidden" name="action" value="delete_member">
                                                <input type="hidden" name="id" value="<?=$m['id']?>">
                                                <input type="hidden" name="return_page" value="members">
                                                <button type="submit" class="btn btn-outline-danger" title="Delete Member">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add / Edit Member Modal -->
            <div class="modal fade <?=$editMember?'show d-block':''?>" id="memberModal" tabindex="-1" style="<?=$editMember?'background: rgba(0,0,0,0.5);':''?>">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="app.php?page=members">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="save_member">
                            <input type="hidden" name="return_page" value="members">
                            <input type="hidden" name="id" value="<?=e((string)($editMember['id']??''))?>">

                            <div class="modal-header">
                                <h5 class="modal-title fw-bold">
                                    <i class="bi bi-person-fill text-primary me-2"></i>
                                    <?=$editMember ? 'Edit Member Details' : 'Register New Library Member'?>
                                </h5>
                                <?php if ($editMember): ?>
                                    <a href="app.php?page=members" class="btn-close"></a>
                                <?php else: ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                <?php endif; ?>
                            </div>

                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Member Identification Number</label>
                                    <input type="text" name="member_no" class="form-control" placeholder="e.g. MEM-1001 (Leave blank to auto-generate)" value="<?=e($editMember['member_no']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Full Name *</label>
                                    <input type="text" name="full_name" class="form-control" placeholder="e.g. Kasun Perera" required value="<?=e($editMember['full_name']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Email Address *</label>
                                    <input type="email" name="email" class="form-control" placeholder="e.g. kasun@example.com" required value="<?=e($editMember['email']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" placeholder="+94 77 123 4567" value="<?=e($editMember['phone']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Postal Address</label>
                                    <textarea name="address" class="form-control" rows="2" placeholder="Member home / workplace address"><?=e($editMember['address']??'')?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" <?=($editMember['status']??'active')==='active'?'selected':''?>>Active</option>
                                        <option value="suspended" <?=($editMember['status']??'')==='suspended'?'selected':''?>>Suspended</option>
                                    </select>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <?php if ($editMember): ?>
                                    <a href="app.php?page=members" class="btn btn-light">Cancel</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Member</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- =======================================================
             MODULE 5: ISSUE BOOKS (LENDING)
             ======================================================= -->
        <?php elseif ($page === 'issues'):
            $membersList = $pdo->query('SELECT id, member_no, full_name, email FROM members WHERE status = "active" ORDER BY full_name')->fetchAll();
            $booksList = $pdo->query('SELECT id, title, isbn, author, available_quantity FROM books WHERE available_quantity > 0 ORDER BY title')->fetchAll();

            $activeIssues = $pdo->query("
                SELECT bi.*, m.full_name, m.member_no, m.phone, b.title, b.isbn, b.rack_no
                FROM book_issues bi
                JOIN members m ON m.id = bi.member_id
                JOIN books b ON b.id = bi.book_id
                WHERE bi.return_date IS NULL
                ORDER BY bi.id DESC
            ")->fetchAll();
        ?>

            <div class="row g-4">
                <!-- Issue Form Panel -->
                <div class="col-lg-5">
                    <div class="card-modern p-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="stat-icon primary" style="width: 40px; height: 40px; font-size: 1.2rem;">
                                <i class="bi bi-arrow-up-right-circle-fill"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0">Issue Book to Member</h5>
                                <small class="text-muted">Record new lending transaction</small>
                            </div>
                        </div>

                        <form method="post" action="app.php?page=issues">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="issue_book">
                            <input type="hidden" name="return_page" value="issues">

                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Select Library Member *</label>
                                <select name="member_id" class="form-select" required>
                                    <option value="">-- Choose Member --</option>
                                    <?php foreach ($membersList as $m): ?>
                                        <option value="<?=$m['id']?>"><?=e($m['member_no'].' — '.$m['full_name'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="form-label fw-semibold small">Select Book to Issue *</label>
                                    <div id="issue_book_stock_badge"></div>
                                </div>
                                <select name="book_id" id="issue_book_select" class="form-select" required>
                                    <option value="">-- Choose Book in Stock --</option>
                                    <?php foreach ($booksList as $b): ?>
                                        <option value="<?=$b['id']?>" data-available="<?=$b['available_quantity']?>">
                                            <?=e($b['title'].' ('.$b['available_quantity'].' available)')?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small">Issue Date *</label>
                                    <input type="date" name="issue_date" class="form-control" value="<?=date('Y-m-d')?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small">Due Date *</label>
                                    <input type="date" name="due_date" id="due_date" class="form-control" value="<?=date('Y-m-d', strtotime('+14 days'))?>" min="<?=date('Y-m-d')?>" required>
                                </div>
                            </div>

                            <!-- Due Date Presets -->
                            <div class="mb-3">
                                <label class="form-label text-muted small mb-1">Quick Duration Presets:</label>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-xs btn-outline-secondary btn-due-preset" data-days="7" data-target="#due_date">7 Days</button>
                                    <button type="button" class="btn btn-xs btn-outline-primary btn-due-preset active" data-days="14" data-target="#due_date">14 Days (Standard)</button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary btn-due-preset" data-days="30" data-target="#due_date">30 Days</button>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold small">Remarks / Notes</label>
                                <input type="text" name="remarks" class="form-control" placeholder="e.g. First edition, academic reference...">
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> Confirm &amp; Issue Book
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Currently Issued Table -->
                <div class="col-lg-7">
                    <div class="card-modern">
                        <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-0">Currently Borrowed Books</h5>
                                <small class="text-muted"><?=count($activeIssues)?> books currently on loan</small>
                            </div>
                            <a href="app.php?page=return" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                <i class="bi bi-arrow-down-left-circle me-1"></i> Return Books
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>Member</th>
                                        <th>Book</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$activeIssues): ?>
                                        <tr><td colspan="4" class="text-center py-5 text-muted">No books currently issued.</td></tr>
                                    <?php else: foreach ($activeIssues as $issue): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold text-dark"><?=e($issue['full_name'])?></div>
                                                <small class="text-muted"><?=e($issue['member_no'])?></small>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?=e($issue['title'])?></div>
                                                <small class="text-muted">Issued: <?=e($issue['issue_date'])?></small>
                                            </td>
                                            <td>
                                                <div class="fw-semibold <?=strtotime($issue['due_date']) < time() ? 'text-danger' : 'text-dark'?>">
                                                    <?=e($issue['due_date'])?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge-status <?=$issue['status']?>">
                                                    <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> <?=ucfirst($issue['status'])?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <!-- =======================================================
             MODULE 6: RETURN BOOKS
             ======================================================= -->
        <?php elseif ($page === 'return'):
            $stmt = $pdo->query("
                SELECT bi.*, m.full_name, m.member_no, m.phone, b.title, b.isbn, b.rack_no
                FROM book_issues bi
                JOIN members m ON m.id = bi.member_id
                JOIN books b ON b.id = bi.book_id
                WHERE bi.return_date IS NULL
                ORDER BY bi.due_date ASC
            ");
            $pendingReturns = $stmt->fetchAll();
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1">Return Borrowed Books</h4>
                    <p class="text-muted small mb-0">Accept returned books, inspect due dates, and calculate overdue fines automatically (Rs. <?=number_format(FINE_PER_DAY,2)?> / day).</p>
                </div>
                <div>
                    <input type="text" id="tableSearchInput" data-target-table="#returnTable tbody" class="form-control form-control-sm rounded-pill px-3" placeholder="Quick search table...">
                </div>
            </div>

            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern" id="returnTable">
                        <thead>
                            <tr>
                                <th>Borrower Member</th>
                                <th>Book Title</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Calculated Fine</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$pendingReturns): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">All issued books have been returned!</td></tr>
                            <?php else: foreach ($pendingReturns as $r):
                                $due = new DateTime($r['due_date']);
                                $today = new DateTime();
                                $daysLate = $today > $due ? (int)$due->diff($today)->format('%a') : 0;
                                $fineAmt = $daysLate * FINE_PER_DAY;
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?=e($r['full_name'])?></div>
                                        <small class="text-muted"><?=e($r['member_no'])?> • <?=e($r['phone']??'')?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?=e($r['title'])?></div>
                                        <small class="text-muted">ISBN: <?=e($r['isbn'] ?? 'N/A')?></small>
                                    </td>
                                    <td><?=e($r['issue_date'])?></td>
                                    <td>
                                        <div class="fw-semibold <?=$daysLate>0?'text-danger':''?>"><?=e($r['due_date'])?></div>
                                        <?php if ($daysLate > 0): ?>
                                            <small class="text-danger fw-bold"><?=$daysLate?> days late</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?=$r['status']?>">
                                            <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> <?=ucfirst($r['status'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($fineAmt > 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger fw-bold px-2 py-1">
                                                <?=format_currency($fineAmt)?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-success fw-semibold"><i class="bi bi-check-circle"></i> No Fine</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-success btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#returnModal<?=$r['id']?>">
                                            <i class="bi bi-box-arrow-in-down-left me-1"></i> Return Book
                                        </button>
                                    </td>
                                </tr>

                                <!-- Return Confirmation Modal -->
                                <div class="modal fade" id="returnModal<?=$r['id']?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content">
                                            <form method="post" action="app.php?page=return">
                                                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                                <input type="hidden" name="action" value="return_book">
                                                <input type="hidden" name="return_page" value="return">
                                                <input type="hidden" name="issue_id" value="<?=$r['id']?>">

                                                <div class="modal-header">
                                                    <h5 class="modal-title fw-bold">
                                                        <i class="bi bi-bookmark-check-fill text-success me-2"></i>
                                                        Confirm Book Return
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <div class="bg-light p-3 rounded-3 border mb-3">
                                                        <div class="small text-muted">Book Title:</div>
                                                        <div class="fw-bold text-dark fs-6"><?=e($r['title'])?></div>
                                                        <div class="small text-muted mt-2">Borrower:</div>
                                                        <div class="fw-semibold text-dark"><?=e($r['full_name'])?> (<?=e($r['member_no'])?>)</div>
                                                    </div>

                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <label class="form-label fw-semibold small">Return Date</label>
                                                            <input type="date" name="return_date" class="form-control" value="<?=date('Y-m-d')?>" required>
                                                        </div>
                                                        <div class="col-6">
                                                            <label class="form-label fw-semibold small">Fine Collected (Rs.)</label>
                                                            <input type="number" step="0.01" name="fine_collected" class="form-control fw-bold <?=$fineAmt>0?'text-danger':''?>" value="<?=$fineAmt?>">
                                                        </div>
                                                    </div>

                                                    <?php if ($fineAmt > 0): ?>
                                                        <div class="alert alert-warning py-2 small">
                                                            <i class="bi bi-info-circle-fill me-1"></i> Overdue penalty: <strong><?=$daysLate?> days</strong> at <strong>Rs. <?=number_format(FINE_PER_DAY,2)?>/day</strong>.
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-semibold small">Condition / Remarks</label>
                                                        <input type="text" name="remarks" class="form-control" placeholder="Book returned in good condition...">
                                                    </div>
                                                </div>

                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-success px-4 fw-semibold">Confirm Return</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- =======================================================
             MODULE 7: OVERDUE BOOKS TRACKER
             ======================================================= -->
        <?php elseif ($page === 'overdue'):
            $stmt = $pdo->query("
                SELECT bi.*, m.full_name, m.member_no, m.phone, m.email, b.title, b.isbn, b.rack_no
                FROM book_issues bi
                JOIN members m ON m.id = bi.member_id
                JOIN books b ON b.id = bi.book_id
                WHERE bi.status = 'overdue' AND bi.return_date IS NULL
                ORDER BY bi.due_date ASC
            ");
            $overdueList = $stmt->fetchAll();
            $totalOverdueFines = array_sum(array_column($overdueList, 'fine'));
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1 text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i> Overdue Loans Tracker</h4>
                    <p class="text-muted small mb-0">Display all books with passed due dates, accumulated fines, and borrower contact details for follow-up reminders.</p>
                </div>
                <div>
                    <span class="badge bg-danger fs-6 px-3 py-2">
                        Total Pending Fines: <?=format_currency($totalOverdueFines)?>
                    </span>
                </div>
            </div>

            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>Borrower Contact</th>
                                <th>Book Title</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Accrued Fine</th>
                                <th class="text-end">Quick Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$overdueList): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-success">
                                        <i class="bi bi-check-circle-fill display-6 d-block mb-2"></i>
                                        <strong>Awesome! There are no overdue books at this time.</strong>
                                    </td>
                                </tr>
                            <?php else: foreach ($overdueList as $od):
                                $due = new DateTime($od['due_date']);
                                $today = new DateTime();
                                $daysLate = (int)$due->diff($today)->format('%a');
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?=e($od['full_name'])?></div>
                                        <small class="text-muted"><?=e($od['member_no'])?> • <a href="tel:<?=e($od['phone'])?>"><?=e($od['phone'])?></a></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?=e($od['title'])?></div>
                                        <small class="text-muted">Shelf: <?=e($od['rack_no'] ?? 'N/A')?></small>
                                    </td>
                                    <td><?=e($od['issue_date'])?></td>
                                    <td><span class="text-danger fw-bold"><?=e($od['due_date'])?></span></td>
                                    <td><span class="badge bg-danger px-2 py-1"><?=$daysLate?> days</span></td>
                                    <td><span class="fw-bold text-danger"><?=format_currency($od['fine'])?></span></td>
                                    <td class="text-end">
                                        <a href="app.php?page=return" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                                            <i class="bi bi-arrow-down-left-circle me-1"></i> Receive Return
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- =======================================================
             MODULE 8: REPORTS & ANALYTICS
             ======================================================= -->
        <?php elseif ($page === 'reports'):
            $reportType = $_GET['type'] ?? 'transactions';
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 no-print">
                <div>
                    <h4 class="fw-bold mb-1">Library Reports &amp; Audit Logs</h4>
                    <p class="text-muted small mb-0">Generate, filter, print, and export circulation and inventory records to CSV.</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" onclick="window.print()">
                        <i class="bi bi-printer-fill me-1"></i> Print Report
                    </button>
                    <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm" onclick="exportTableToCSV('#reportTable', 'library-report-<?=date('Ymd')?>.csv')">
                        <i class="bi bi-download me-1"></i> Export to CSV
                    </button>
                </div>
            </div>

            <!-- Report Navigation Tabs -->
            <ul class="nav nav-pills mb-3 no-print">
                <li class="nav-item">
                    <a class="nav-link <?=$reportType==='transactions'?'active':''?>" href="app.php?page=reports&type=transactions">
                        <i class="bi bi-arrow-left-right me-1"></i> Issued &amp; Returned Books
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?=$reportType==='inventory'?'active':''?>" href="app.php?page=reports&type=inventory">
                        <i class="bi bi-journals me-1"></i> Books Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?=$reportType==='members'?'active':''?>" href="app.php?page=reports&type=members">
                        <i class="bi bi-people me-1"></i> Members Directory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?=$reportType==='fines'?'active':''?>" href="app.php?page=reports&type=fines">
                        <i class="bi bi-cash-coin me-1"></i> Fines Collected Log
                    </a>
                </li>
            </ul>

            <!-- Printable Header (Visible only when printed) -->
            <div class="print-header">
                <h2>Leaf &amp; Lore - Library Management System</h2>
                <p>Generated on <?=date('d M Y, h:i A')?> | Report: <?=ucfirst($reportType)?></p>
                <hr>
            </div>

            <!-- Report Table Content -->
            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern" id="reportTable">
                        <?php if ($reportType === 'transactions'):
                            $stmt = $pdo->query("
                                SELECT bi.*, m.full_name, m.member_no, b.title, b.isbn
                                FROM book_issues bi
                                JOIN members m ON m.id = bi.member_id
                                JOIN books b ON b.id = bi.book_id
                                ORDER BY bi.id DESC
                            ");
                            $rows = $stmt->fetchAll();
                        ?>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Member</th>
                                    <th>Book Title</th>
                                    <th>Issue Date</th>
                                    <th>Due Date</th>
                                    <th>Return Date</th>
                                    <th>Status</th>
                                    <th>Fine (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>#<?=$r['id']?></td>
                                        <td><?=e($r['full_name'])?> (<?=e($r['member_no'])?>)</td>
                                        <td><?=e($r['title'])?></td>
                                        <td><?=$r['issue_date']?></td>
                                        <td><?=$r['due_date']?></td>
                                        <td><?=$r['return_date'] ?: 'Not returned'?></td>
                                        <td><?=ucfirst($r['status'])?></td>
                                        <td><?=number_format((float)$r['fine'], 2)?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        <?php elseif ($reportType === 'inventory'):
                            $rows = $pdo->query('SELECT b.*, c.category_name FROM books b LEFT JOIN categories c ON c.id = b.category_id ORDER BY b.title')->fetchAll();
                        ?>
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>Category</th>
                                    <th>ISBN</th>
                                    <th>Total Copies</th>
                                    <th>Available Copies</th>
                                    <th>Shelf Location</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><strong><?=e($r['title'])?></strong></td>
                                        <td><?=e($r['author'])?></td>
                                        <td><?=e($r['category_name'] ?? '—')?></td>
                                        <td><?=e($r['isbn'] ?? '—')?></td>
                                        <td><?=$r['quantity']?></td>
                                        <td><?=$r['available_quantity']?></td>
                                        <td><?=e($r['rack_no'] ?? '—')?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        <?php elseif ($reportType === 'members'):
                            $rows = $pdo->query('SELECT * FROM members ORDER BY member_no')->fetchAll();
                        ?>
                            <thead>
                                <tr>
                                    <th>Member No</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Registration Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?=e($r['member_no'])?></td>
                                        <td><strong><?=e($r['full_name'])?></strong></td>
                                        <td><?=e($r['email'])?></td>
                                        <td><?=e($r['phone'] ?? '—')?></td>
                                        <td><?=ucfirst($r['status'])?></td>
                                        <td><?=date('d M Y', strtotime($r['created_at']))?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        <?php elseif ($reportType === 'fines'):
                            $rows = $pdo->query('
                                SELECT br.*, m.full_name, m.member_no, b.title, u.name AS received_by_user
                                FROM book_returns br
                                JOIN book_issues bi ON bi.id = br.issue_id
                                JOIN members m ON m.id = bi.member_id
                                JOIN books b ON b.id = bi.book_id
                                LEFT JOIN users u ON u.id = br.received_by
                                ORDER BY br.id DESC
                            ')->fetchAll();
                        ?>
                            <thead>
                                <tr>
                                    <th>Return ID</th>
                                    <th>Member</th>
                                    <th>Book Title</th>
                                    <th>Return Date</th>
                                    <th>Overdue Days</th>
                                    <th>Fine Collected (Rs.)</th>
                                    <th>Received By</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>#<?=$r['id']?></td>
                                        <td><?=e($r['full_name'])?></td>
                                        <td><?=e($r['title'])?></td>
                                        <td><?=$r['return_date']?></td>
                                        <td><?=$r['overdue_days']?> days</td>
                                        <td><strong>Rs. <?=number_format((float)$r['fine_collected'], 2)?></strong></td>
                                        <td><?=e($r['received_by_user'] ?? 'System')?></td>
                                        <td><?=e($r['remarks'] ?? '—')?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

        <!-- =======================================================
             MODULE 9: USER MANAGEMENT (ADMIN ONLY)
             ======================================================= -->
        <?php elseif ($page === 'users'):
            $usersList = $pdo->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY id DESC')->fetchAll();
            $editUser = null;
            if (isset($_GET['edit'])) {
                $s = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
                $s->execute([(int)$_GET['edit']]);
                $editUser = $s->fetch();
            }
        ?>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1">System Users &amp; Permissions</h4>
                    <p class="text-muted small mb-0">Manage staff accounts, administrators, and library operators.</p>
                </div>
                <button type="button" class="btn btn-primary rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#userModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Add System User
                </button>
            </div>

            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usersList as $u): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                <?=strtoupper(substr($u['name'], 0, 1))?>
                                            </div>
                                            <div class="fw-bold text-dark"><?=e($u['name'])?></div>
                                        </div>
                                    </td>
                                    <td><?=e($u['email'])?></td>
                                    <td>
                                        <span class="badge bg-<?=$u['role']==='admin'?'primary':($u['role']==='librarian'?'info':'secondary')?>-subtle text-<?=$u['role']==='admin'?'primary':($u['role']==='librarian'?'info':'secondary')?> border px-2 py-1">
                                            <?=ucfirst($u['role'])?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?=$u['status']?>">
                                            <i class="bi bi-circle-fill" style="font-size: 0.5rem;"></i> <?=ucfirst($u['status'])?>
                                        </span>
                                    </td>
                                    <td><?=date('d M Y', strtotime($u['created_at']))?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="app.php?page=users&edit=<?=$u['id']?>" class="btn btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                            <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="id" value="<?=$u['id']?>">
                                                    <input type="hidden" name="return_page" value="users">
                                                    <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add / Edit User Modal -->
            <div class="modal fade <?=$editUser?'show d-block':''?>" id="userModal" tabindex="-1" style="<?=$editUser?'background: rgba(0,0,0,0.5);':''?>">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="app.php?page=users">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <input type="hidden" name="action" value="save_user">
                            <input type="hidden" name="return_page" value="users">
                            <input type="hidden" name="id" value="<?=e((string)($editUser['id']??''))?>">

                            <div class="modal-header">
                                <h5 class="modal-title fw-bold">
                                    <i class="bi bi-person-gear text-primary me-2"></i>
                                    <?=$editUser ? 'Edit User Account' : 'Create New System User'?>
                                </h5>
                                <?php if ($editUser): ?>
                                    <a href="app.php?page=users" class="btn-close"></a>
                                <?php else: ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                <?php endif; ?>
                            </div>

                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Full Name *</label>
                                    <input type="text" name="name" class="form-control" placeholder="e.g. Sarah Jenkins" required value="<?=e($editUser['name']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Email Address *</label>
                                    <input type="email" name="email" class="form-control" placeholder="e.g. sarah@library.com" required value="<?=e($editUser['email']??'')?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Password <?=$editUser?'(Leave blank to keep current)':'*' ?></label>
                                    <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" minlength="6" <?=$editUser?'':'required'?>>
                                </div>

                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label fw-semibold small">System Role</label>
                                        <select name="role" class="form-select">
                                            <option value="librarian" <?=($editUser['role']??'librarian')==='librarian'?'selected':''?>>Librarian</option>
                                            <option value="admin" <?=($editUser['role']??'')==='admin'?'selected':''?>>Administrator</option>
                                            <option value="member" <?=($editUser['role']??'')==='member'?'selected':''?>>Member</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold small">Account Status</label>
                                        <select name="status" class="form-select">
                                            <option value="active" <?=($editUser['status']??'active')==='active'?'selected':''?>>Active</option>
                                            <option value="inactive" <?=($editUser['status']??'')==='inactive'?'selected':''?>>Inactive</option>
                                            <option value="suspended" <?=($editUser['status']??'')==='suspended'?'selected':''?>>Suspended</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <?php if ($editUser): ?>
                                    <a href="app.php?page=users" class="btn btn-light">Cancel</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary px-4 fw-semibold">Save User</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($page === 'activity_logs'):
            $activityLogs = $pdo->query("
                SELECT * FROM (
                    SELECT bi.created_at AS occurred_at, 'Book Issued' AS action_name,
                           COALESCE(u.name, 'System') AS actor,
                           CONCAT(b.title, ' issued to ', m.full_name) AS details,
                           'primary' AS tone
                    FROM book_issues bi
                    JOIN books b ON b.id = bi.book_id
                    JOIN members m ON m.id = bi.member_id
                    LEFT JOIN users u ON u.id = bi.issued_by
                    UNION ALL
                    SELECT br.created_at, 'Book Returned', COALESCE(u.name, 'System'),
                           CONCAT(b.title, ' returned by ', m.full_name), 'success'
                    FROM book_returns br
                    JOIN book_issues bi ON bi.id = br.issue_id
                    JOIN books b ON b.id = bi.book_id
                    JOIN members m ON m.id = bi.member_id
                    LEFT JOIN users u ON u.id = br.received_by
                    UNION ALL
                    SELECT created_at, 'Member Registered', 'System', CONCAT(full_name, ' (', member_no, ')'), 'info'
                    FROM members
                    UNION ALL
                    SELECT created_at, 'Book Added', 'System', CONCAT(title, ' by ', author), 'secondary'
                    FROM books
                ) activity
                ORDER BY occurred_at DESC
                LIMIT 150
            ")->fetchAll();
        ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
                <div><h4 class="fw-bold mb-1">Activity Logs</h4><p class="text-muted small mb-0">Recent catalog, membership, lending, and return activity.</p></div>
                <span class="badge bg-primary-subtle text-primary border rounded-pill px-3 py-2"><?=count($activityLogs)?> recent events</span>
            </div>
            <div class="card-modern">
                <div class="table-responsive">
                    <table class="table-modern">
                        <thead><tr><th>Date &amp; Time</th><th>Activity</th><th>Performed By</th><th>Details</th></tr></thead>
                        <tbody>
                        <?php if (!$activityLogs): ?>
                            <tr><td colspan="4" class="text-center text-muted py-5">No activity has been recorded yet.</td></tr>
                        <?php else: foreach ($activityLogs as $log): ?>
                            <tr>
                                <td class="text-nowrap"><?=date('d M Y, h:i A', strtotime($log['occurred_at']))?></td>
                                <td><span class="badge bg-<?=e($log['tone'])?>-subtle text-<?=e($log['tone'])?> border"><?=e($log['action_name'])?></span></td>
                                <td class="fw-semibold"><?=e($log['actor'])?></td>
                                <td><?=e($log['details'])?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($page === 'database_backup'):
            $tableStats = $pdo->query("SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = " . $pdo->quote(DB_NAME) . " ORDER BY table_name")->fetchAll();
            $tableCount = count($tableStats);
            $databaseSize = 0.0;
            $liveRecords = 0;
            foreach ($tableStats as &$tableStat) {
                $safeStatTable = str_replace('`', '``', $tableStat['table_name']);
                $tableStat['live_rows'] = (int)$pdo->query("SELECT COUNT(*) FROM `{$safeStatTable}`")->fetchColumn();
                $liveRecords += $tableStat['live_rows'];
                $databaseSize += (float)$tableStat['data_length'] + (float)$tableStat['index_length'];
            }
            unset($tableStat);
        ?>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div><h4 class="fw-bold mb-1"><i class="bi bi-cloud-arrow-down-fill text-primary me-2"></i>Database Backup Management</h4><p class="text-muted small mb-0">Generate, download, and review your complete library database.</p></div>
                <div class="d-flex gap-2"><span class="badge bg-light text-secondary border px-3 py-2"><i class="bi bi-database me-1"></i><?=e(DB_NAME)?></span><span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size:.45rem"></i>MySQL</span></div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="backup-metric"><small>Total Tables</small><strong><?=$tableCount?></strong><span><i class="bi bi-table"></i> Library schema</span></div></div>
                <div class="col-md-4"><div class="backup-metric"><small>Live Records</small><strong><?=number_format($liveRecords)?></strong><span><i class="bi bi-list-check"></i> Current rows</span></div></div>
                <div class="col-md-4"><div class="backup-metric"><small>Storage Size</small><strong><?=number_format($databaseSize / 1048576, 2)?> MB</strong><span><i class="bi bi-device-ssd"></i> Data &amp; indexes</span></div></div>
            </div>

            <div class="card-modern backup-download-panel p-4 text-center mb-3">
                <div class="stat-icon success mx-auto mb-3"><i class="bi bi-shield-check"></i></div>
                <h5 class="fw-bold mb-1">Create &amp; Download SQL Backup</h5>
                <p class="text-muted small mb-3">Generate a complete SQL dump containing your database structure and all current records.</p>
                <form method="post" action="app.php?page=database_backup">
                    <input type="hidden" name="csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="download_database_backup"><input type="hidden" name="return_page" value="database_backup">
                    <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Generate &amp; Download SQL Backup</button>
                </form>
            </div>

            <div class="card-modern p-4 mb-3">
                <h6 class="fw-bold mb-3"><i class="bi bi-arrow-clockwise me-2 text-primary"></i>Database Restore &amp; Recovery Instructions</h6>
                <ol class="small text-secondary mb-3 ps-3"><li class="mb-2"><strong class="text-dark">Using phpMyAdmin:</strong> select the database, open <em>Import</em>, choose the downloaded SQL file, and run the import.</li><li><strong class="text-dark">Using the MySQL command line:</strong></li></ol>
                <code class="backup-command">mysql -u <?=e(DB_USER)?> -p <?=e(DB_NAME)?> &lt; <?=e(DB_NAME)?>-backup.sql</code>
            </div>

            <div class="card-modern">
                <div class="p-3 border-bottom"><h6 class="fw-bold mb-0"><i class="bi bi-table me-2 text-primary"></i>Database Table Schema Overview</h6></div>
                <div class="table-responsive"><table class="table-modern"><thead><tr><th>#</th><th>Table Name</th><th class="text-end">Row Count</th><th class="text-end">Data Size</th></tr></thead><tbody>
                <?php foreach ($tableStats as $index => $stat): $tableBytes = (float)$stat['data_length'] + (float)$stat['index_length']; ?>
                    <tr><td class="text-muted"><?=$index + 1?></td><td class="fw-semibold"><i class="bi bi-table text-muted me-2"></i><?=e($stat['table_name'])?></td><td class="text-end"><?=number_format($stat['live_rows'])?></td><td class="text-end"><?=number_format($tableBytes / 1024, 2)?> KB</td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </div>

        <?php endif; ?>
        </main>
    </div>
</div>

<!-- Logout Confirmation Modal (Matching User Design) -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 330px;">
        <div class="modal-content text-center border-0 shadow-lg" style="border-radius: 28px; background: linear-gradient(180deg, #f0f7ff 0%, #ffffff 50%); padding: 2.2rem 1.8rem 1.8rem;">
            <!-- Circle Icon with Door/Logout Outline -->
            <div class="d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 68px; height: 68px; border-radius: 50%; background: #e8f0fe; color: #1e293b;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </div>

            <!-- Title & Subtitle -->
            <h4 class="fw-bold text-dark mb-2" style="font-size: 1.45rem; letter-spacing: -0.02em;">Logout</h4>
            <p class="text-secondary small mb-4" style="font-size: 0.92rem;">Are you sure you want to logout?</p>

            <!-- Action Buttons (Cancel & Logout) -->
            <div class="d-flex align-items-center justify-content-between gap-3 w-100">
                <button type="button" class="btn btn-link text-decoration-none fw-semibold text-primary px-3 py-2 flex-grow-1" data-bs-dismiss="modal" style="font-size: 0.95rem;">
                    Cancel
                </button>
                <a href="/auth/logout.php" class="btn btn-primary px-4 py-2.5 fw-semibold text-white text-decoration-none shadow-sm flex-grow-1" style="border-radius: 14px; background: #2563eb; border: none; font-size: 0.95rem;">
                    Logout
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5.3 Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Main Application JS -->
<script src="/js/app.js"></script>

</body>
</html>
