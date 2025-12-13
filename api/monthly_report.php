<?php
// api/monthly_report.php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/session_manager.php';

try {
    if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['admin', 'assistante'])) {
        throw new Exception("Accès non autorisé.");
    }

    $pdo = pdo_conn();
    
    // Parsing du paramètre month (YYYY-MM)
    $monthStr = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) {
        $monthStr = date('Y-m');
    }
    list($year, $month) = explode('-', $monthStr);
    $year = (int)$year;
    $month = (int)$month;

    setlocale(LC_TIME, 'fr_FR.UTF-8', 'fra');
    // Fallback pour la date si setlocale ne marche pas bien sur toutes les plateformes
    $monthName = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"][$month - 1];
    $month_label = "$monthName $year";

    // --- 1. STATS DE PRODUCTION (PHOTOGRAPHES) ---
    $sql_prod = "SELECT COUNT(DISTINCT p.id) as total_products_shot, SUM(IFNULL(p.nb_vues, 0)) as total_views_shot FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND YEAR(p.date_photo_ok) = ? AND MONTH(p.date_photo_ok) = ?";
    $stmt_prod = $pdo->prepare($sql_prod);
    $stmt_prod->execute([$year, $month]);
    $prod_stats = $stmt_prod->fetch(PDO::FETCH_ASSOC);

    // --- 2. PERFORMANCE PAR PHOTOGRAPHE ---
    $sql_perf = "SELECT u.identifiant, COUNT(DISTINCT p.id) as products_count, SUM(IFNULL(p.nb_vues, 0)) as views_count FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND YEAR(p.date_photo_ok) = ? AND MONTH(p.date_photo_ok) = ? GROUP BY u.identifiant ORDER BY products_count DESC";
    $stmt_perf = $pdo->prepare($sql_perf);
    $stmt_perf->execute([$year, $month]);
    $photographer_performance = $stmt_perf->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. PRODUCTION PAR DEMANDEUR (ex-TYPOLOGIE) ---
    $sql_type = "SELECT COALESCE(NULLIF(p.demandeur, ''), '(Non renseigné)') as demandeur, COUNT(DISTINCT p.id) as products_count, SUM(IFNULL(p.nb_vues, 0)) as views_count FROM products p JOIN users u ON u.id = p.photographe_user_id WHERE u.role = 'photographe' AND YEAR(p.date_photo_ok) = ? AND MONTH(p.date_photo_ok) = ? GROUP BY demandeur ORDER BY products_count DESC";
    $stmt_type = $pdo->prepare($sql_type);
    $stmt_type->execute([$year, $month]);
    $by_type_performance = $stmt_type->fetchAll(PDO::FETCH_ASSOC);

    // --- 4. ANALYSE DES DÉLAIS ---
    $sql_deadline = "SELECT 
        COUNT(CASE WHEN p.date_photo_ok <= p.deadline THEN 1 END) as on_time_count, 
        COUNT(CASE WHEN p.date_photo_ok > p.deadline THEN 1 END) as late_count, 
        AVG(DATEDIFF(p.date_photo_ok, p.deadline)) as avg_delay_days 
        FROM products p 
        JOIN users u ON u.id = p.photographe_user_id 
        WHERE u.role = 'photographe' 
        AND YEAR(p.date_photo_ok) = ? AND MONTH(p.date_photo_ok) = ? 
        AND p.deadline IS NOT NULL";
    $stmt_deadline = $pdo->prepare($sql_deadline);
    $stmt_deadline->execute([$year, $month]);
    $deadline_stats = $stmt_deadline->fetch(PDO::FETCH_ASSOC);
    
    $total_deadline = ($deadline_stats['on_time_count'] ?? 0) + ($deadline_stats['late_count'] ?? 0);
    $on_time_percentage = $total_deadline > 0 ? round(($deadline_stats['on_time_count'] / $total_deadline) * 100) : 0;
    $avg_delay = $deadline_stats['avg_delay_days'] ? round($deadline_stats['avg_delay_days'], 1) : 0;

    // --- 5. ÉTAT DU BACKLOG ACTUEL ---
    $total_backlog = $pdo->query("SELECT COUNT(*) FROM products WHERE statut_photo = 'à shooter'")->fetchColumn();
    $backlog_status = $pdo->query("SELECT COALESCE(NULLIF(statut_echantillon, ''), '(Non renseigné)') as statut_echantillon, COUNT(*) as count FROM products WHERE statut_photo = 'à shooter' GROUP BY statut_echantillon ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);

    // --- ASSEMBLAGE DE LA RÉPONSE ---
    echo json_encode([
        'success' => true,
        'meta' => [
            'month_label' => $month_label,
            'year' => $year,
            'month' => $month
        ],
        'data' => [
            'total_products_shot' => (int)($prod_stats['total_products_shot'] ?? 0),
            'total_views_shot' => (int)($prod_stats['total_views_shot'] ?? 0),
            'on_time_percentage' => $on_time_percentage,
            'late_count' => (int)($deadline_stats['late_count'] ?? 0),
            'avg_delay_days' => $avg_delay,
            'photographer_performance' => $photographer_performance,
            'by_type_performance' => $by_type_performance,
            'backlog_status' => $backlog_status,
            'total_backlog' => (int)$total_backlog
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(200); // On renvoie 200 pour que le JS puisse lire le message d'erreur JSON
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}