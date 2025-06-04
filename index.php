<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'pdf_store_v2');
define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST'] . str_replace('index.php', '', $_SERVER['SCRIPT_NAME']));

// Start session
session_start();

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle flash messages
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// Database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Create tables if not exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        email VARCHAR(100) NOT NULL,
        transaction_id VARCHAR(255) NOT NULL,
        courses_purchased TEXT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL,
        order_status ENUM('Pending', 'Confirmed') NOT NULL DEFAULT 'Pending',
        purchase_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )
");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Registration
    if (isset($_POST['register'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        $errors = [];
        
        if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
            $errors[] = "All fields are required.";
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        
        if (strlen($password) < 6) {
            $errors[] = "Password must be at least 6 characters long.";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
        
        if (empty($errors)) {
            // Check if email or username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? OR username = ?");
            $stmt->execute([$email, $username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Email or username already exists.";
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
                if ($stmt->execute([$username, $email, $password_hash])) {
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Registration successful! Please login.'];
                    header("Location: ?view=login");
                    exit;
                } else {
                    $errors[] = "Registration failed. Please try again.";
                }
            }
        }
    }
    
    // Login
    if (isset($_POST['login'])) {
        $email_username = trim($_POST['email_username']);
        $password = $_POST['password'];
        
        $errors = [];
        
        if (empty($email_username) || empty($password)) {
            $errors[] = "Email/username and password are required.";
        }
        
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
            $stmt->execute([$email_username, $email_username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ];
                
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Login successful!'];
                header("Location: .");
                exit;
            } else {
                $errors[] = "Invalid email/username or password.";
            }
        }
    }
    
    // Add to cart (AJAX handled separately)
    
    // Update cart
    if (isset($_POST['update_cart'])) {
        if (isset($_POST['remove'])) {
            $course_id = (int)$_POST['remove'];
            if (isset($_SESSION['cart'][$course_id])) {
                unset($_SESSION['cart'][$course_id]);
            }
        }
        header("Location: ?view=cart");
        exit;
    }
    
    // Payment
    if (isset($_POST['confirm_purchase'])) {
        $email = trim($_POST['email']);
        $transaction_id = trim($_POST['transaction_id']);
        
        $errors = [];
        
        if (empty($email) || empty($transaction_id)) {
            $errors[] = "Email and transaction ID are required.";
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        
        if (empty($_SESSION['cart'])) {
            $errors[] = "Your cart is empty.";
        }
        
        if (empty($errors)) {
            // Get cart items
            $courses = [];
            $total = 0;
            foreach ($_SESSION['cart'] as $id => $item) {
                $courses[] = $item['name'];
                $total += $item['price'];
            }
            
            // Save transaction
            $user_id = isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null;
            $courses_purchased = implode(", ", $courses);
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (user_id, email, transaction_id, courses_purchased, total_amount) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user_id,
                $email,
                $transaction_id,
                $courses_purchased,
                $total
            ]);
            
            // Clear cart
            $_SESSION['cart'] = [];
            
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Purchase confirmed! Your PDFs will be delivered shortly.'];
            header("Location: .");
            exit;
        }
    }
    
    // Admin confirm order
    if (isset($_POST['confirm_order'])) {
        if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin') {
            $transaction_id = (int)$_POST['transaction_id'];
            $stmt = $pdo->prepare("UPDATE transactions SET order_status = 'Confirmed' WHERE id = ?");
            $stmt->execute([$transaction_id]);
        }
        header("Location: ?view=admin");
        exit;
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['user']);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'You have been logged out.'];
    header("Location: .");
    exit;
}

// Course data
$courses = [
    [
        'id' => 1,
        'name' => 'Web Development Fundamentals',
        'description' => 'Master the building blocks of modern web development with HTML, CSS, and JavaScript.',
        'price' => 49,
        'image' => 'https://source.unsplash.com/random/600x400?web-development',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?web-development'
    ],
    [
        'id' => 2,
        'name' => 'Graphic Design Principles',
        'description' => 'Learn the fundamentals of visual communication and create stunning designs.',
        'price' => 39,
        'image' => 'https://source.unsplash.com/random/600x400?graphic-design',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?design'
    ],
    [
        'id' => 3,
        'name' => 'Python for Data Science',
        'description' => 'Harness the power of Python for data analysis, visualization, and machine learning.',
        'price' => 59,
        'image' => 'https://source.unsplash.com/random/600x400?python',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?data-science'
    ],
    [
        'id' => 4,
        'name' => 'Digital Marketing Mastery',
        'description' => 'Develop effective marketing strategies for the digital age.',
        'price' => 45,
        'image' => 'https://source.unsplash.com/random/600x400?marketing',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?digital-marketing'
    ],
    [
        'id' => 5,
        'name' => 'Mobile App Development (React Native)',
        'description' => 'Build cross-platform mobile apps with React Native.',
        'price' => 69,
        'image' => 'https://source.unsplash.com/random/600x400?react-native',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?mobile-app'
    ],
    [
        'id' => 6,
        'name' => 'Cybersecurity Essentials',
        'description' => 'Protect systems and networks from digital attacks.',
        'price' => 55,
        'image' => 'https://source.unsplash.com/random/600x400?cybersecurity',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?security'
    ],
    [
        'id' => 7,
        'name' => 'Cloud Computing with AWS',
        'description' => 'Learn to deploy, manage, and scale applications on AWS.',
        'price' => 79,
        'image' => 'https://source.unsplash.com/random/600x400?aws',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?cloud-computing'
    ],
    [
        'id' => 8,
        'name' => 'Project Management (PMP Prep)',
        'description' => 'Master project management methodologies and prepare for PMP certification.',
        'price' => 89,
        'image' => 'https://source.unsplash.com/random/600x400?project-management',
        'hero_image' => 'https://source.unsplash.com/random/1200x600?management'
    ]
];

// Featured courses for hero slider
$featured_courses = array_slice($courses, 0, 4);

// Get current view
$view = 'home';
if (isset($_GET['view'])) {
    $allowed_views = ['register', 'login', 'cart', 'payment', 'admin'];
    if (in_array($_GET['view'], $allowed_views)) {
        $view = $_GET['view'];
    }
}

// Calculate cart total and count
$cart_total = 0;
$cart_count = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_total += $item['price'];
    $cart_count += 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LearnSphere - Premium PDF Courses</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #f72585;
            --dark: #14213d;
            --light: #f8f9fa;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #4cc9f0;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Open Sans', sans-serif;
            background-color: #f5f7fb;
            color: var(--dark);
            line-height: 1.6;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .logo i {
            margin-right: 10px;
        }
        
        nav ul {
            display: flex;
            list-style: none;
        }
        
        nav li {
            margin-left: 25px;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
        }
        
        nav a:hover {
            color: var(--success);
        }
        
        nav a i {
            margin-right: 8px;
        }
        
        .cart-count {
            background-color: var(--secondary);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            margin-left: 6px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
        }
        
        .user-info span {
            margin-right: 15px;
            font-weight: 600;
        }
        
        /* Hero Slider */
        .hero-slider {
            height: 500px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 20px 20px;
            margin-bottom: 60px;
        }
        
        .slides {
            height: 100%;
            position: relative;
        }
        
        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 1s ease-in-out;
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            padding: 0 50px;
        }
        
        .slide.active {
            opacity: 1;
        }
        
        .slide-content {
            max-width: 600px;
            background: rgba(255, 255, 255, 0.85);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .slide h2 {
            font-size: 2.5rem;
            color: var(--dark);
            margin-bottom: 15px;
        }
        
        .slide p {
            font-size: 1.2rem;
            color: var(--gray);
            margin-bottom: 25px;
        }
        
        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 12px 28px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background: var(--secondary);
        }
        
        .btn-secondary:hover {
            background: #e31771;
        }
        
        .slider-nav {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
        }
        
        .slider-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            margin: 0 8px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .slider-dot.active {
            background: white;
        }
        
        /* Section Styling */
        section {
            padding: 80px 0;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .section-header h2 {
            font-size: 2.5rem;
            color: var(--dark);
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }
        
        .section-header h2:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: var(--primary);
            border-radius: 2px;
        }
        
        .section-header p {
            font-size: 1.2rem;
            color: var(--gray);
            max-width: 700px;
            margin: 20px auto 0;
        }
        
        /* Course Grid */
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
        }
        
        .course-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
            transition: var(--transition);
            position: relative;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.6s forwards;
        }
        
        .course-card:nth-child(2) { animation-delay: 0.1s; }
        .course-card:nth-child(3) { animation-delay: 0.2s; }
        .course-card:nth-child(4) { animation-delay: 0.3s; }
        .course-card:nth-child(5) { animation-delay: 0.4s; }
        .course-card:nth-child(6) { animation-delay: 0.5s; }
        .course-card:nth-child(7) { animation-delay: 0.6s; }
        .course-card:nth-child(8) { animation-delay: 0.7s; }
        
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .course-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .course-img {
            height: 180px;
            background-size: cover;
            background-position: center;
        }
        
        .course-content {
            padding: 25px;
        }
        
        .course-content h3 {
            font-size: 1.4rem;
            margin-bottom: 12px;
            color: var(--dark);
        }
        
        .course-content p {
            color: var(--gray);
            margin-bottom: 20px;
            min-height: 60px;
        }
        
        .course-price {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }
        
        .price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .add-to-cart {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
        }
        
        .add-to-cart i {
            margin-right: 8px;
        }
        
        .add-to-cart:hover {
            background: var(--primary-dark);
        }
        
        /* Forms */
        .form-container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-header h2 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 10px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--light-gray);
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        .form-footer {
            text-align: center;
            margin-top: 20px;
        }
        
        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .form-footer a:hover {
            text-decoration: underline;
        }
        
        /* Cart */
        .cart-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .cart-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .cart-header h2 {
            font-size: 1.8rem;
        }
        
        .cart-total {
            font-size: 1.4rem;
            font-weight: 700;
        }
        
        .cart-items {
            padding: 30px;
        }
        
        .cart-item {
            display: flex;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .cart-item-img {
            width: 100px;
            height: 70px;
            border-radius: 10px;
            background-size: cover;
            background-position: center;
            margin-right: 20px;
        }
        
        .cart-item-details {
            flex: 1;
        }
        
        .cart-item-details h3 {
            margin-bottom: 8px;
            font-size: 1.2rem;
        }
        
        .cart-item-price {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .remove-item {
            background: none;
            border: none;
            color: var(--gray);
            cursor: pointer;
            font-size: 1.2rem;
            transition: var(--transition);
        }
        
        .remove-item:hover {
            color: var(--secondary);
        }
        
        .cart-actions {
            display: flex;
            justify-content: space-between;
            padding: 30px;
            border-top: 1px solid var(--light-gray);
        }
        
        /* Payment */
        .payment-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .payment-info {
            padding: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        
        .payment-info h2 {
            font-size: 2rem;
            margin-bottom: 25px;
        }
        
        .payment-info p {
            margin-bottom: 15px;
            font-size: 1.1rem;
        }
        
        .qr-code {
            text-align: center;
            margin: 30px 0;
        }
        
        .qr-code img {
            max-width: 200px;
            border: 10px solid white;
            border-radius: 10px;
        }
        
        .payment-form {
            padding: 40px;
        }
        
        /* Admin */
        .admin-container {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .admin-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 20px 30px;
        }
        
        .admin-header h2 {
            font-size: 1.8rem;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table th,
        .transactions-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .transactions-table th {
            background-color: var(--light);
            font-weight: 700;
        }
        
        .transactions-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .status-pending {
            color: #e9c46a;
            font-weight: 700;
        }
        
        .status-confirmed {
            color: #2a9d8f;
            font-weight: 700;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }
        
        /* Flash messages */
        .flash-message {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            transform: translateX(120%);
            transition: transform 0.3s ease;
        }
        
        .flash-message.show {
            transform: translateX(0);
        }
        
        .flash-success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
        }
        
        .flash-error {
            background: linear-gradient(135deg, #f72585, #b5179e);
        }
        
        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 60px 0 30px;
            margin-top: 80px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }
        
        .footer-column h3 {
            font-size: 1.4rem;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-column h3:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary);
        }
        
        .footer-column p {
            margin-bottom: 20px;
            color: #adb5bd;
        }
        
        .social-links {
            display: flex;
            gap: 15px;
        }
        
        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transition: var(--transition);
        }
        
        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-5px);
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 12px;
        }
        
        .footer-links a {
            color: #adb5bd;
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-links a:hover {
            color: var(--primary);
            padding-left: 5px;
        }
        
        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #adb5bd;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .payment-container {
                grid-template-columns: 1fr;
            }
            
            .payment-info {
                order: 1;
            }
            
            .payment-form {
                order: 2;
            }
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                padding: 15px 0;
            }
            
            nav ul {
                margin-top: 15px;
            }
            
            nav li {
                margin: 0 10px;
            }
            
            .hero-slider {
                height: 400px;
            }
            
            .slide-content {
                padding: 20px;
            }
            
            .slide h2 {
                font-size: 2rem;
            }
            
            .cart-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .cart-item-img {
                margin-bottom: 15px;
            }
            
            .cart-actions {
                flex-direction: column;
                gap: 15px;
            }
            
            .cart-actions .btn {
                width: 100%;
                text-align: center;
            }
        }
        
        @media (max-width: 576px) {
            .section-header h2 {
                font-size: 2rem;
            }
            
            .form-container {
                padding: 30px 20px;
            }
            
            .cart-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Flash Message -->
    <?php if (isset($flash)): ?>
        <div class="flash-message flash-<?= $flash['type'] ?> show">
            <?= $flash['message'] ?>
        </div>
    <?php endif; ?>
    
    <!-- Header -->
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-book-open"></i>
                    LearnSphere
                </div>
                <nav>
                    <ul>
                        <li><a href="."><i class="fas fa-home"></i> Courses</a></li>
                        <li>
                            <a href="?view=cart">
                                <i class="fas fa-shopping-cart"></i> Cart
                                <?php if ($cart_count > 0): ?>
                                    <span class="cart-count"><?= $cart_count ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php if (isset($_SESSION['user'])): ?>
                            <li class="user-info">
                                <span><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['user']['username']) ?></span>
                                <?php if ($_SESSION['user']['role'] === 'admin'): ?>
                                    <a href="?view=admin"><i class="fas fa-cog"></i> Admin</a>
                                <?php endif; ?>
                                <a href="?logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                            </li>
                        <?php else: ?>
                            <li><a href="?view=login"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                            <li><a href="?view=register"><i class="fas fa-user-plus"></i> Register</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <?php if ($view === 'home'): ?>
            <!-- Hero Slider -->
            <div class="hero-slider">
                <div class="slides">
                    <?php foreach ($featured_courses as $index => $course): ?>
                        <div class="slide <?= $index === 0 ? 'active' : '' ?>" 
                             style="background-image: url('<?= $course['hero_image'] ?>');">
                            <div class="container">
                                <div class="slide-content">
                                    <h2><?= htmlspecialchars($course['name']) ?></h2>
                                    <p><?= htmlspecialchars($course['description']) ?></p>
                                    <a href="#course-<?= $course['id'] ?>" class="btn btn-secondary">Get PDF</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="slider-nav">
                    <?php for ($i = 0; $i < count($featured_courses); $i++): ?>
                        <div class="slider-dot <?= $i === 0 ? 'active' : '' ?>"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Courses Section -->
            <section id="courses">
                <div class="container">
                    <div class="section-header">
                        <h2>Our Premium PDF Courses</h2>
                        <p>Expand your knowledge with our expertly crafted PDF courses. Download instantly and learn at your own pace.</p>
                    </div>
                    
                    <div class="courses-grid">
                        <?php foreach ($courses as $course): ?>
                            <div class="course-card" id="course-<?= $course['id'] ?>">
                                <div class="course-img" style="background-image: url('<?= $course['image'] ?>');"></div>
                                <div class="course-content">
                                    <h3><?= htmlspecialchars($course['name']) ?></h3>
                                    <p><?= htmlspecialchars($course['description']) ?></p>
                                    <div class="course-price">
                                        <div class="price">₹<?= $course['price'] ?></div>
                                        <button class="add-to-cart" data-id="<?= $course['id'] ?>" data-name="<?= htmlspecialchars($course['name']) ?>" 
                                                data-price="<?= $course['price'] ?>" data-image="<?= $course['image'] ?>">
                                            <i class="fas fa-cart-plus"></i> Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Register View -->
        <?php if ($view === 'register'): ?>
            <section>
                <div class="container">
                    <div class="form-container">
                        <div class="form-header">
                            <h2>Create an Account</h2>
                            <p>Join LearnSphere to purchase our premium PDF courses</p>
                        </div>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($errors as $error): ?>
                                    <p><?= $error ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                            </div>
                            
                            <button type="submit" name="register" class="btn btn-block">Register</button>
                            
                            <div class="form-footer">
                                <p>Already have an account? <a href="?view=login">Login here</a></p>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Login View -->
        <?php if ($view === 'login'): ?>
            <section>
                <div class="container">
                    <div class="form-container">
                        <div class="form-header">
                            <h2>Login to Your Account</h2>
                            <p>Access your courses and purchase history</p>
                        </div>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($errors as $error): ?>
                                    <p><?= $error ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label for="email_username">Email or Username</label>
                                <input type="text" id="email_username" name="email_username" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" class="form-control" required>
                            </div>
                            
                            <button type="submit" name="login" class="btn btn-block">Login</button>
                            
                            <div class="form-footer">
                                <p>Don't have an account? <a href="?view=register">Register here</a></p>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Cart View -->
        <?php if ($view === 'cart'): ?>
            <section>
                <div class="container">
                    <div class="cart-container">
                        <div class="cart-header">
                            <h2><i class="fas fa-shopping-cart"></i> Your Shopping Cart</h2>
                            <div class="cart-total">Total: ₹<?= $cart_total ?></div>
                        </div>
                        
                        <?php if (empty($_SESSION['cart'])): ?>
                            <div class="cart-items" style="text-align: center; padding: 60px;">
                                <h3>Your cart is empty</h3>
                                <p>Browse our courses and add some PDFs to your cart!</p>
                                <a href="." class="btn" style="margin-top: 20px;">Browse Courses</a>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <div class="cart-items">
                                    <?php foreach ($_SESSION['cart'] as $id => $item): ?>
                                        <div class="cart-item">
                                            <div class="cart-item-img" style="background-image: url('<?= $item['image'] ?>');"></div>
                                            <div class="cart-item-details">
                                                <h3><?= htmlspecialchars($item['name']) ?></h3>
                                                <div class="cart-item-price">₹<?= $item['price'] ?></div>
                                            </div>
                                            <button type="submit" name="remove" value="<?= $id ?>" class="remove-item">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <input type="hidden" name="update_cart" value="1">
                                
                                <div class="cart-actions">
                                    <a href="." class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Continue Shopping
                                    </a>
                                    <a href="?view=payment" class="btn">
                                        Proceed to Payment <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Payment View -->
        <?php if ($view === 'payment'): ?>
            <section>
                <div class="container">
                    <div class="payment-container">
                        <div class="payment-info">
                            <h2>Complete Your Purchase</h2>
                            <p>Total Amount: <strong>₹<?= $cart_total ?></strong></p>
                            <p>Scan the QR code with any UPI app to make payment.</p>
                            
                            <div class="qr-code">
                                <?php 
                                $qr_data = "upi://pay?pa=learnsphere@upi&pn=LearnSphere&am=$cart_total&cu=INR";
                                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($qr_data);
                                ?>
                                <img src="<?= $qr_url ?>" alt="Payment QR Code">
                            </div>
                            
                            <p>After payment, enter the transaction ID below to confirm your purchase.</p>
                        </div>
                        
                        <div class="payment-form">
                            <div class="form-header">
                                <h2>Payment Details</h2>
                                <p>Enter your information to complete the transaction</p>
                            </div>
                            
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($errors as $error): ?>
                                        <p><?= $error ?></p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST">
                                <div class="form-group">
                                    <label for="email">Email Address</label>
                                    <input type="email" id="email" name="email" class="form-control" 
                                           value="<?= isset($_SESSION['user']['email']) ? htmlspecialchars($_SESSION['user']['email']) : '' ?>" required>
                                    <small>We'll send your PDFs to this email</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="transaction_id">Transaction ID</label>
                                    <input type="text" id="transaction_id" name="transaction_id" class="form-control" placeholder="Enter UPI transaction ID" required>
                                </div>
                                
                                <div class="form-group">
                                    <p><strong>Order Summary:</strong></p>
                                    <ul>
                                        <?php foreach ($_SESSION['cart'] as $item): ?>
                                            <li><?= htmlspecialchars($item['name']) ?> (₹<?= $item['price'] ?>)</li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p>Total: ₹<?= $cart_total ?></p>
                                </div>
                                
                                <button type="submit" name="confirm_purchase" class="btn btn-block">
                                    Confirm Purchase <i class="fas fa-check"></i>
                                </button>
                                
                                <div style="text-align: center; margin-top: 20px;">
                                    <a href="?view=cart" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Cart
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Admin View -->
        <?php if ($view === 'admin'): ?>
            <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin'): ?>
                <section>
                    <div class="container">
                        <div class="admin-container">
                            <div class="admin-header">
                                <h2><i class="fas fa-cog"></i> Admin Dashboard</h2>
                            </div>
                            
                            <div style="padding: 30px;">
                                <h3 style="margin-bottom: 20px; font-size: 1.5rem;">Transaction History</h3>
                                
                                <?php
                                $stmt = $pdo->query("
                                    SELECT t.*, u.username 
                                    FROM transactions t 
                                    LEFT JOIN users u ON t.user_id = u.id 
                                    ORDER BY purchase_timestamp DESC
                                ");
                                $transactions = $stmt->fetchAll();
                                ?>
                                
                                <?php if (empty($transactions)): ?>
                                    <p>No transactions found.</p>
                                <?php else: ?>
                                    <div style="overflow-x: auto;">
                                        <table class="transactions-table">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>User</th>
                                                    <th>Email</th>
                                                    <th>Transaction ID</th>
                                                    <th>Courses</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                    <th>Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transactions as $transaction): ?>
                                                    <tr>
                                                        <td><?= $transaction['id'] ?></td>
                                                        <td><?= $transaction['username'] ? htmlspecialchars($transaction['username']) : 'Guest' ?></td>
                                                        <td><?= htmlspecialchars($transaction['email']) ?></td>
                                                        <td><?= htmlspecialchars($transaction['transaction_id']) ?></td>
                                                        <td><?= htmlspecialchars($transaction['courses_purchased']) ?></td>
                                                        <td>₹<?= $transaction['total_amount'] ?></td>
                                                        <td>
                                                            <?php if ($transaction['order_status'] === 'Pending'): ?>
                                                                <span class="status-pending">Pending</span>
                                                            <?php else: ?>
                                                                <span class="status-confirmed">Confirmed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= date('d M Y, H:i', strtotime($transaction['purchase_timestamp'])) ?></td>
                                                        <td>
                                                            <?php if ($transaction['order_status'] === 'Pending'): ?>
                                                                <form method="POST" style="display: inline-block;">
                                                                    <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                                                                    <button type="submit" name="confirm_order" class="btn btn-sm">
                                                                        Confirm
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span>Confirmed</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section>
                    <div class="container">
                        <div class="form-container">
                            <div class="form-header">
                                <h2>Access Denied</h2>
                                <p>You must be an administrator to view this page.</p>
                            </div>
                            <div style="text-align: center; margin-top: 30px;">
                                <a href="." class="btn">Back to Home</a>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>LearnSphere</h3>
                    <p>Your premier destination for high-quality PDF courses. Learn at your own pace, anytime, anywhere.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                
                <div class="footer-column">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href=".">Home</a></li>
                        <li><a href="#courses">Courses</a></li>
                        <li><a href="?view=cart">Cart</a></li>
                        <li><a href="#">FAQs</a></li>
                        <li><a href="#">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Courses</h3>
                    <ul class="footer-links">
                        <?php foreach (array_slice($courses, 0, 5) as $course): ?>
                            <li><a href="#course-<?= $course['id'] ?>"><?= htmlspecialchars($course['name']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Contact Info</h3>
                    <ul class="footer-links">
                        <li><i class="fas fa-envelope"></i> support@learnsphere.com</li>
                        <li><i class="fas fa-phone"></i> +91 98765 43210</li>
                        <li><i class="fas fa-map-marker-alt"></i> 123 Education Street, Mumbai, India</li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                <p>&copy; <?= date('Y') ?> LearnSphere. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <script>
        // Hero Slider
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize slider
            const slides = document.querySelectorAll('.slide');
            const dots = document.querySelectorAll('.slider-dot');
            let currentSlide = 0;
            
            function showSlide(n) {
                slides.forEach(slide => slide.classList.remove('active'));
                dots.forEach(dot => dot.classList.remove('active'));
                
                slides[n].classList.add('active');
                dots[n].classList.add('active');
                currentSlide = n;
            }
            
            function nextSlide() {
                let next = currentSlide + 1;
                if (next >= slides.length) next = 0;
                showSlide(next);
            }
            
            // Auto slide
            let slideInterval = setInterval(nextSlide, 5000);
            
            // Dot navigation
            dots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    clearInterval(slideInterval);
                    showSlide(index);
                    slideInterval = setInterval(nextSlide, 5000);
                });
            });
            
            // Add to cart functionality
            const addToCartButtons = document.querySelectorAll('.add-to-cart');
            addToCartButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const course = {
                        id: this.dataset.id,
                        name: this.dataset.name,
                        price: parseFloat(this.dataset.price),
                        image: this.dataset.image
                    };
                    
                    // AJAX request to add to cart
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `add_to_cart=1&course_id=${course.id}&course_name=${encodeURIComponent(course.name)}&price=${course.price}&image=${encodeURIComponent(course.image)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show success message
                            const flash = document.createElement('div');
                            flash.className = 'flash-message flash-success show';
                            flash.innerHTML = `<i class="fas fa-check-circle"></i> ${course.name} added to cart!`;
                            document.body.appendChild(flash);
                            
                            // Remove message after 3 seconds
                            setTimeout(() => {
                                flash.classList.remove('show');
                                setTimeout(() => flash.remove(), 300);
                            }, 3000);
                            
                            // Update cart count
                            document.querySelector('.cart-count').textContent = data.cart_count;
                            
                            // If cart was empty, update the display
                            if (data.cart_count === 1) {
                                const cartLink = document.querySelector('a[href="?view=cart"]');
                                cartLink.innerHTML = '<i class="fas fa-shopping-cart"></i> Cart <span class="cart-count">1</span>';
                            }
                        }
                    });
                });
            });
            
            // Flash message animation
            const flashMessage = document.querySelector('.flash-message');
            if (flashMessage) {
                setTimeout(() => {
                    flashMessage.classList.remove('show');
                    setTimeout(() => flashMessage.remove(), 300);
                }, 3000);
            }
            
            // Scroll animations
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.1
            };
            
            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animated');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            // Observe elements
            document.querySelectorAll('.course-card').forEach(card => {
                observer.observe(card);
            });
        });
    </script>
</body>
</html>
<?php
// Handle AJAX add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $course_id = (int)$_POST['course_id'];
    $course_name = $_POST['course_name'];
    $price = (float)$_POST['price'];
    $image = $_POST['image'];
    
    // Add to cart session
    $_SESSION['cart'][$course_id] = [
        'id' => $course_id,
        'name' => $course_name,
        'price' => $price,
        'image' => $image
    ];
    
    // Calculate new cart count
    $cart_count = count($_SESSION['cart']);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'cart_count' => $cart_count
    ]);
    exit;
}
?>