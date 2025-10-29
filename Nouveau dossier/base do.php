<?php
// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'artisan_local');
define('DB_USER', 'root');
define('DB_PASS', '');

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Erreur de connexion: " . $e->getMessage());
}

// Démarrer la session
session_start();

// Vérifier si l'utilisateur est connecté
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Vérifier le type d'utilisateur
function getUserType() {
    return $_SESSION['user_type'] ?? null;
}

// Rediriger si non connecté
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

// Rediriger selon le type d'utilisateur
function redirectByUserType() {
    if (isLoggedIn()) {
        $userType = getUserType();
        if ($userType == 'admin') {
            header("Location: admin/dashboard.php");
        } else {
            header("Location: dashboard.php");
        }
        exit();
    }
}
?>

<?php
require_once 'config.php';

// Traitement de la connexion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    // Rechercher l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        // Connexion réussie
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['full_name'] = $user['full_name'];
        
        // Redirection selon le type d'utilisateur
        if ($user['user_type'] == 'admin') {
            header("Location: admin/dashboard.php");
        } else {
            header("Location: dashboard.php");
        }
        exit();
    } else {
        $login_error = "Email ou mot de passe incorrect.";
    }
}

// Traitement de l'inscription
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $full_name = trim($_POST['full_name']);
    $user_type = $_POST['user_type'] ?? 'client';
    
    // Validation
    if ($password !== $confirm_password) {
        $register_error = "Les mots de passe ne correspondent pas.";
    } else {
        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $register_error = "Cet email est déjà utilisé.";
        } else {
            // Hasher le mot de passe
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insérer l'utilisateur
            $stmt = $pdo->prepare("INSERT INTO users (email, password, full_name, user_type) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$email, $hashed_password, $full_name, $user_type])) {
                // Connecter automatiquement l'utilisateur
                $user_id = $pdo->lastInsertId();
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_type'] = $user_type;
                $_SESSION['full_name'] = $full_name;
                
                // Redirection
                if ($user_type == 'admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit();
            } else {
                $register_error = "Une erreur s'est produite lors de l'inscription.";
            }
        }
    }
}
?>



<?php
require_once 'config.php';
requireLogin();

// Récupérer les informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

if ($user_type == 'artisan') {
    // Récupérer les demandes de service pour l'artisan
    $stmt = $pdo->prepare("
        SELECT sr.*, u.full_name as client_name, s.title as service_title 
        FROM service_requests sr 
        JOIN users u ON sr.client_id = u.id 
        JOIN services s ON sr.service_id = s.id 
        WHERE sr.artisan_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $service_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les services de l'artisan
    $stmt = $pdo->prepare("SELECT * FROM services WHERE artisan_id = ?");
    $stmt->execute([$user_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else if ($user_type == 'client') {
    // Récupérer les demandes de service pour le client
    $stmt = $pdo->prepare("
        SELECT sr.*, u.full_name as artisan_name, s.title as service_title 
        FROM service_requests sr 
        JOIN users u ON sr.artisan_id = u.id 
        JOIN services s ON sr.service_id = s.id 
        WHERE sr.client_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $service_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupérer le profil utilisateur
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - ArtisanLocal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-nav {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
            height: 100vh;
            position: fixed;
            padding-top: 20px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .stats-card {
            border-radius: 10px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Navigation du tableau de bord -->
            <div class="col-md-2 dashboard-nav">
                <h4 class="text-center mb-4">ArtisanLocal</h4>
                <div class="text-center mb-4">
                    <img src="https://via.placeholder.com/100" class="rounded-circle mb-2" alt="Avatar">
                    <h6><?php echo htmlspecialchars($user['full_name']); ?></h6>
                    <span class="badge bg-<?php echo $user_type == 'artisan' ? 'primary' : 'success'; ?>">
                        <?php echo ucfirst($user_type); ?>
                    </span>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Tableau de bord</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php"><i class="fas fa-user me-2"></i> Mon profil</a>
                    </li>
                    <?php if ($user_type == 'artisan'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php"><i class="fas fa-concierge-bell me-2"></i> Mes services</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php"><i class="fas fa-list me-2"></i> Demandes</a>
                    </li>
                    <?php if ($user_type == 'client'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="artisans.php"><i class="fas fa-hands-helping me-2"></i> Artisans</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Déconnexion</a>
                    </li>
                </ul>
            </div>

            <!-- Contenu principal -->
            <div class="col-md-10 main-content">
                <h2 class="mb-4">Tableau de bord</h2>
                
                <?php if ($user_type == 'artisan'): ?>
                <!-- Statistiques pour les artisans -->
                <div class="row">
                    <div class="col-md-3">
                        <div class="card stats-card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo count($services); ?></h5>
                                <p class="card-text">Services proposés</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $pending = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'pending';
                                    });
                                    echo count($pending);
                                    ?>
                                </h5>
                                <p class="card-text">Demandes en attente</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $accepted = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'accepted';
                                    });
                                    echo count($accepted);
                                    ?>
                                </h5>
                                <p class="card-text">Services acceptés</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $completed = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'completed';
                                    });
                                    echo count($completed);
                                    ?>
                                </h5>
                                <p class="card-text">Services terminés</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Demandes récentes</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($service_requests) > 0): ?>
                                    <?php foreach (array_slice($service_requests, 0, 5) as $request): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($request['title']); ?></h6>
                                            <small class="text-muted">De: <?php echo htmlspecialchars($request['client_name']); ?></small>
                                        </div>
                                        <span class="badge bg-<?php 
                                            switch($request['status']) {
                                                case 'pending': echo 'warning'; break;
                                                case 'accepted': echo 'success'; break;
                                                case 'rejected': echo 'danger'; break;
                                                case 'completed': echo 'info'; break;
                                            }
                                        ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">Aucune demande pour le moment.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>Mes services</h5>
                            </div>
                            <div class="card-body">
                                <?php if (count($services) > 0): ?>
                                    <?php foreach (array_slice($services, 0, 5) as $service): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($service['title']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($service['category']); ?></small>
                                        </div>
                                        <a href="edit_service.php?id=<?php echo $service['id']; ?>" class="btn btn-sm btn-outline-primary">Modifier</a>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">Vous n'avez pas encore de services.</p>
                                    <a href="add_service.php" class="btn btn-primary">Ajouter un service</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- Tableau de bord pour les clients -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="card stats-card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $pending = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'pending';
                                    });
                                    echo count($pending);
                                    ?>
                                </h5>
                                <p class="card-text">Demandes en attente</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $accepted = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'accepted';
                                    });
                                    echo count($accepted);
                                    ?>
                                </h5>
                                <p class="card-text">Services acceptés</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card stats-card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php 
                                    $completed = array_filter($service_requests, function($req) {
                                        return $req['status'] == 'completed';
                                    });
                                    echo count($completed);
                                    ?>
                                </h5>
                                <p class="card-text">Services terminés</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5>Mes demandes récentes</h5>
                                <a href="artisans.php" class="btn btn-primary">Nouvelle demande</a>
                            </div>
                            <div class="card-body">
                                <?php if (count($service_requests) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Service</th>
                                                    <th>Artisan</th>
                                                    <th>Date demande</th>
                                                    <th>Statut</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($service_requests, 0, 5) as $request): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($request['service_title']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['artisan_name']); ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($request['created_at'])); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            switch($request['status']) {
                                                                case 'pending': echo 'warning'; break;
                                                                case 'accepted': echo 'success'; break;
                                                                case 'rejected': echo 'danger'; break;
                                                                case 'completed': echo 'info'; break;
                                                            }
                                                        ?>">
                                                            <?php echo ucfirst($request['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="request_detail.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-primary">Détails</a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">Vous n'avez pas encore fait de demandes.</p>
                                    <a href="artisans.php" class="btn btn-primary">Trouver un artisan</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>




<?php
// create_request.php
require_once 'config.php';
requireLogin();

// Vérifier que l'utilisateur est un client
if ($_SESSION['user_type'] != 'client') {
    header("Location: dashboard.php");
    exit();
}

// Vérifier que les données nécessaires sont présentes
if ($_SERVER['REQUEST_METHOD'] != 'POST' || !isset($_POST['artisan_id']) || !isset($_POST['service_id'])) {
    header("Location: artisans.php");
    exit();
}

$client_id = $_SESSION['user_id'];
$artisan_id = $_POST['artisan_id'];
$service_id = $_POST['service_id'];
$title = trim($_POST['title']);
$description = trim($_POST['description']);
$requested_date = !empty($_POST['requested_date']) ? $_POST['requested_date'] : null;

// Validation
if (empty($title) || empty($description)) {
    $_SESSION['error'] = "Le titre et la description sont obligatoires.";
    header("Location: artisan_detail.php?id=" . $artisan_id);
    exit();
}

// Vérifier que le service appartient bien à l'artisan
$stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND artisan_id = ?");
$stmt->execute([$service_id, $artisan_id]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    $_SESSION['error'] = "Service non valide.";
    header("Location: artisan_detail.php?id=" . $artisan_id);
    exit();
}

// Créer la demande
$stmt = $pdo->prepare("INSERT INTO service_requests (client_id, artisan_id, service_id, title, description, requested_date) VALUES (?, ?, ?, ?, ?, ?)");
if ($stmt->execute([$client_id, $artisan_id, $service_id, $title, $description, $requested_date])) {
    $_SESSION['success'] = "Votre demande a été envoyée avec succès.";
} else {
    $_SESSION['error'] = "Une erreur s'est produite lors de l'envoi de votre demande.";
}

header("Location: requests.php");
exit();
?>




<?php
// Basculer entre les formulaires
const loginToggle = document.getElementById('login-toggle');
const signupToggle = document.getElementById('signup-toggle');
const loginForm = document.getElementById('login-form');
const signupForm = document.getElementById('signup-form');

loginToggle.addEventListener('click', () => {
    loginForm.classList.add('active');
    signupForm.classList.remove('active');
    loginToggle.classList.add('active');
    signupToggle.classList.remove('active');
});

signupToggle.addEventListener('click', () => {
    signupForm.classList.add('active');
    loginForm.classList.remove('active');
    signupToggle.classList.add('active');
    loginToggle.classList.remove('active');
});

// Validation simple pour l'inscription
signupForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    
    if (password !== confirmPassword) {
        alert('Les mots de passe ne correspondent pas.');
    } else {
        alert('Inscription réussie ! (Simulation - ajoutez une vraie logique backend)');
        // Ici, vous pouvez ajouter une requête AJAX pour envoyer les données à un serveur
    }
});

// Validation simple pour la connexion
loginForm.addEventListener('submit', (e) => {
    e.preventDefault();
    alert('Connexion réussie ! (Simulation - ajoutez une vraie logique backend)');
    // Ici, vous pouvez ajouter une vérification avec un serveur
});

?>



