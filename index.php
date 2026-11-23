
<?php
session_start();

$conn = new mysqli("localhost", "root", "", "canteen_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// CREATE TABLES AUTOMATICALLY
$conn->query("CREATE TABLE IF NOT EXISTS students (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
email VARCHAR(100) UNIQUE NOT NULL,
password VARCHAR(255) NOT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS admins (
id INT AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(100) UNIQUE NOT NULL,
password VARCHAR(255) NOT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS food_items (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
description TEXT,
price DECIMAL(10,2) NOT NULL,
category VARCHAR(100),
availability TINYINT DEFAULT 1
)");

$conn->query("CREATE TABLE IF NOT EXISTS orders (
id INT AUTO_INCREMENT PRIMARY KEY,
student_id INT NOT NULL,
total_amount DECIMAL(10,2) NOT NULL,
status VARCHAR(50) DEFAULT 'Pending',
order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS order_items (
id INT AUTO_INCREMENT PRIMARY KEY,
order_id INT NOT NULL,
food_id INT NOT NULL,
quantity INT NOT NULL,
price DECIMAL(10,2) NOT NULL
)");

// DEFAULT ADMIN
$check = $conn->query("SELECT id FROM admins LIMIT 1");
if ($check->num_rows == 0) {
    $username = "admin";
    $password = password_hash("admin123", PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO admins(username,password) VALUES (?,?)");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
}

// DEFAULT FOOD
$check = $conn->query("SELECT id FROM food_items LIMIT 1");
if ($check->num_rows == 0) {
    $foods = [
        ["Masala Dosa", "Crispy dosa with masala", 50, "Breakfast"],
        ["Idli", "Soft idli with chutney", 30, "Breakfast"],
        ["Veg Fried Rice", "Delicious fried rice", 80, "Lunch"],
        ["Veg Burger", "Fresh vegetable burger", 60, "Snacks"],
        ["Tea", "Hot milk tea", 15, "Drinks"],
        ["Coffee", "Fresh hot coffee", 25, "Drinks"]
    ];

    $stmt = $conn->prepare(
        "INSERT INTO food_items(name,description,price,category)
         VALUES (?,?,?,?)"
    );
    foreach ($foods as $f) {
        $stmt->bind_param("ssds", $f[0], $f[1], $f[2], $f[3]);
        $stmt->execute();
    }
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function go($page) {
    header("Location: index.php?page=" . urlencode($page));
    exit;
}

function isAdmin() {
    return isset($_SESSION['admin_id']);
}

function isStudent() {
    return isset($_SESSION['student_id']);
}

$page = $_GET['page'] ?? 'home';
$message = "";

// LOGOUT
if ($page == 'logout') {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

// STUDENT REGISTRATION
if (isset($_POST['register'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if ($name == "" || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($password) < 6) {
        $message = "Enter valid details. Password must have at least 6 characters.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "INSERT INTO students(name,email,password) VALUES(?,?,?)"
        );
        $stmt->bind_param("sss", $name, $email, $hash);

        if ($stmt->execute()) {
            $message = "Registration successful! Please login.";
            $page = "login";
        } else {
            $message = "Email already registered.";
        }
    }
}

// STUDENT LOGIN
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM students WHERE email=?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();

    if ($student && password_verify($password, $student['password'])) {
        session_regenerate_id(true);
        $_SESSION['student_id'] = $student['id'];
        $_SESSION['student_name'] = $student['name'];
        go('dashboard');
    } else {
        $message = "Invalid email or password.";
        $page = "login";
    }
}

// ADMIN LOGIN
if (isset($_POST['admin_login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();

    if ($admin && password_verify($password, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['username'];
        go('admin');
    } else {
        $message = "Invalid admin credentials.";
        $page = "admin_login";
    }
}

// ADD TO CART
if (isset($_POST['add_cart']) && isStudent()) {
    $id = (int)$_POST['food_id'];
    $stmt = $conn->prepare(
        "SELECT id FROM food_items WHERE id=? AND availability=1"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;
        $message = "Food added to cart!";
    }
    $page = "menu";
}

// REMOVE CART ITEM
if (isset($_POST['remove_cart']) && isStudent()) {
    $id = (int)$_POST['food_id'];
    unset($_SESSION['cart'][$id]);
    $page = "cart";
}

// UPDATE CART QUANTITY
if (isset($_POST['update_cart']) && isStudent()) {
    foreach ($_POST['qty'] ?? [] as $id => $qty) {
        $id = (int)$id;
        $qty = max(1, min(50, (int)$qty));
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id] = $qty;
        }
    }
    $page = "cart";
}

// CHECKOUT
if (isset($_POST['checkout']) && isStudent()) {
    $cart = $_SESSION['cart'] ?? [];
    $total = 0;
    $validItems = [];

    foreach ($cart as $id => $qty) {
        $id = (int)$id;
        $qty = max(1, min(50, (int)$qty));

        $stmt = $conn->prepare(
            "SELECT id,price FROM food_items WHERE id=? AND availability=1"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $food = $stmt->get_result()->fetch_assoc();

        if ($food) {
            $validItems[] = [
                'id' => $id,
                'qty' => $qty,
                'price' => (float)$food['price']
            ];
            $total += $food['price'] * $qty;
        }
    }

    if (!$validItems) {
        $message = "Your cart is empty or unavailable.";
        $page = "cart";
    } else {
        $conn->begin_transaction();

        try {
            $sid = (int)$_SESSION['student_id'];
            $stmt = $conn->prepare(
                "INSERT INTO orders(student_id,total_amount) VALUES(?,?)"
            );
            $stmt->bind_param("id", $sid, $total);
            $stmt->execute();
            $orderId = $conn->insert_id;

            $stmt = $conn->prepare(
                "INSERT INTO order_items(order_id,food_id,quantity,price)
                 VALUES(?,?,?,?)"
            );

            foreach ($validItems as $item) {
                $stmt->bind_param(
                    "iiid",
                    $orderId,
                    $item['id'],
                    $item['qty'],
                    $item['price']
                );
                $stmt->execute();
            }

            $conn->commit();
            unset($_SESSION['cart']);
            $message = "Order placed successfully!";
            $page = "orders";
        } catch (Throwable $e) {
            $conn->rollback();
            $message = "Could not place order. Please try again.";
            $page = "cart";
        }
    }
}

// ADMIN ADD FOOD
if (isset($_POST['add_food']) && isAdmin()) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = (float)$_POST['price'];
    $category = trim($_POST['category']);

    if ($name != "" && $price > 0 && $category != "") {
        $stmt = $conn->prepare(
            "INSERT INTO food_items(name,description,price,category)
             VALUES(?,?,?,?)"
        );
        $stmt->bind_param("ssds", $name, $description, $price, $category);
        $stmt->execute();
        $message = "Food item added!";
    } else {
        $message = "Enter valid food details.";
    }
    $page = "admin";
}

// ADMIN DELETE FOOD
if (isset($_POST['delete_food']) && isAdmin()) {
    $id = (int)$_POST['food_id'];
    $stmt = $conn->prepare(
        "UPDATE food_items SET availability=0 WHERE id=?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $message = "Food item removed from menu.";
    $page = "admin";
}

// ADMIN UPDATE ORDER
if (isset($_POST['update_status']) && isAdmin()) {
    $id = (int)$_POST['order_id'];
    $status = $_POST['status'];
    $allowed = ['Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled'];

    if (in_array($status, $allowed, true)) {
        $stmt = $conn->prepare("UPDATE orders SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $message = "Order status updated.";
    }
    $page = "admin";
}

if (in_array($page, ['dashboard', 'menu', 'cart', 'orders']) && !isStudent()) {
    $page = "login";
}

if ($page == 'admin' && !isAdmin()) {
    $page = "admin_login";
}

if ($page == 'admin_login' && isAdmin()) {
    $page = 'admin';
}

// PAGE OUTPUT
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Canteen Order Management</title>

<style>
* {
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body {
    font-family:Arial,sans-serif;
    background:#f5f6fa;
    color:#333;
}

header {
    background:#ff6b35;
    color:white;
    padding:20px 7%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
}

header h2 {
    font-size:25px;
}

nav a {
    color:white;
    text-decoration:none;
    margin:8px;
    font-weight:bold;
}

nav a:hover {
    text-decoration:underline;
}

.hero {
    text-align:center;
    padding:100px 20px;
    background:linear-gradient(135deg,#fff0df,#ffd5b5);
}

.hero h1 {
    font-size:45px;
    color:#d94816;
    margin-bottom:20px;
}

.hero p {
    font-size:18px;
    margin-bottom:30px;
}

.container {
    width:90%;
    max-width:1100px;
    margin:35px auto;
}

.box {
    background:white;
    padding:30px;
    max-width:500px;
    margin:40px auto;
    border-radius:12px;
    box-shadow:0 3px 15px #ddd;
}

h2 {
    margin-bottom:20px;
    color:#e65100;
}

input,textarea,select {
    width:100%;
    padding:12px;
    margin:10px 0;
    border:1px solid #ccc;
    border-radius:6px;
}

button,.btn {
    background:#ff6b35;
    color:white;
    border:0;
    padding:12px 20px;
    border-radius:6px;
    cursor:pointer;
    text-decoration:none;
    display:inline-block;
    margin:5px 0;
}

button:hover,.btn:hover {
    background:#d94b19;
}

.grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:20px;
}

.food-card,.card {
    background:white;
    padding:22px;
    border-radius:10px;
    box-shadow:0 2px 10px #ddd;
}

.food-card h3 {
    margin:10px 0;
}

.price {
    color:#e65100;
    font-size:21px;
    font-weight:bold;
    margin:12px 0;
}

.message {
    background:#e0f7e9;
    color:#176b35;
    padding:15px;
    margin:15px auto;
    width:90%;
    max-width:700px;
    border-radius:6px;
}

table {
    width:100%;
    border-collapse:collapse;
    background:white;
    margin-top:20px;
}

th,td {
    padding:13px;
    border:1px solid #ddd;
    text-align:left;
}

th {
    background:#ff6b35;
    color:white;
}

footer {
    text-align:center;
    background:#333;
    color:white;
    padding:20px;
    margin-top:50px;
}

@media(max-width:600px) {
    header {
        justify-content:center;
        text-align:center;
    }
    .hero h1 {
        font-size:32px;
    }
    table {
        font-size:12px;
    }
}
</style>
</head>

<body>

<header>
<h2>🍔 College Canteen</h2>

<nav>
<a href="index.php">Home</a>

<?php if(isStudent()): ?>
<a href="index.php?page=dashboard">Dashboard</a>
<a href="index.php?page=menu">Menu</a>
<a href="index.php?page=cart">Cart</a>
<a href="index.php?page=orders">My Orders</a>
<a href="index.php?page=logout">Logout</a>

<?php elseif(isAdmin()): ?>
<a href="index.php?page=admin">Admin Dashboard</a>
<a href="index.php?page=logout">Logout</a>

<?php else: ?>
<a href="index.php?page=login">Login</a>
<a href="index.php?page=register">Register</a>
<a href="index.php?page=admin_login">Admin</a>
<?php endif; ?>
</nav>
</header>

<?php if($message != ""): ?>
<div class="message"><?=h($message)?></div>
<?php endif; ?>

<main class="container">

<?php if($page == 'home'): ?>

<section class="hero">
<h1>Welcome to Our Canteen!</h1>
<p>Order Your Favourite Food Online</p>
<p>Fresh food • Best quality • Easy ordering</p>
<a class="btn" href="index.php?page=register">Get Started</a>
<a class="btn" href="index.php?page=login">Student Login</a>
</section>

<?php elseif($page == 'register'): ?>

<div class="box">
<h2>Student Registration</h2>
<form method="POST">
<input type="text" name="name" placeholder="Full Name" required>
<input type="email" name="email" placeholder="Email Address" required>
<input type="password" name="password" placeholder="Password (minimum 6 characters)" minlength="6" required>
<button name="register">Register</button>
</form>
<p>Already registered? <a href="index.php?page=login">Login</a></p>
</div>

<?php elseif($page == 'login'): ?>

<div class="box">
<h2>Student Login</h2>
<form method="POST">
<input type="email" name="email" placeholder="Email Address" required>
<input type="password" name="password" placeholder="Password" required>
<button name="login">Login</button>
</form>
<p>New student? <a href="index.php?page=register">Register here</a></p>
</div>

<?php elseif($page == 'admin_login'): ?>

<div class="box">
<h2>Admin Login</h2>
<form method="POST">
<input type="text" name="username" placeholder="Admin Username" required>
<input type="password" name="password" placeholder="Admin Password" required>
<button name="admin_login">Admin Login</button>
</form>
<p>Default username: admin</p>
<p>Default password: admin123</p>
</div>

<?php elseif($page == 'dashboard'): ?>

<h2>Welcome, <?=h($_SESSION['student_name'])?>!</h2>
<div class="grid">
<div class="card">
<h3>🍕 Food Menu</h3>
<p>Explore delicious food items.</p>
<a class="btn" href="index.php?page=menu">View Menu</a>
</div>
<div class="card">
<h3>🛒 My Cart</h3>
<p>View your selected food.</p>
<a class="btn" href="index.php?page=cart">Open Cart</a>
</div>
<div class="card">
<h3>📦 My Orders</h3>
<p>Check your order status.</p>
<a class="btn" href="index.php?page=orders">View Orders</a>
</div>
</div>

<?php elseif($page == 'menu'): ?>

<h2>Our Food Menu</h2>
<input type="text" id="search" onkeyup="searchFood()" placeholder="Search food...">

<div class="grid">
<?php
$foods = $conn->query("SELECT * FROM food_items WHERE availability=1 ORDER BY id DESC");
while($food = $foods->fetch_assoc()):
?>
<div class="food-card">
<h3><?=h($food['name'])?></h3>
<p><?=h($food['description'])?></p>
<p>Category: <?=h($food['category'])?></p>
<p class="price">₹<?=number_format($food['price'],2)?></p>
<form method="POST">
<input type="hidden" name="food_id" value="<?=$food['id']?>">
<button name="add_cart">Add to Cart</button>
</form>
</div>
<?php endwhile; ?>
</div>

<?php elseif($page == 'cart'): ?>

<h2>My Shopping Cart</h2>

<?php
$cart = $_SESSION['cart'] ?? [];
$total = 0;
if (!$cart) {
    echo "<p>Your cart is empty.</p>";
} else {
?>
<form method="POST">
<table>
<tr>
<th>Food</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th>Action</th>
</tr>

<?php
foreach($cart as $id=>$qty):
$id = (int)$id;
$stmt = $conn->prepare("SELECT * FROM food_items WHERE id=? AND availability=1");
$stmt->bind_param("i",$id);
$stmt->execute();
$food = $stmt->get_result()->fetch_assoc();
if(!$food) continue;

$subtotal = $food['price'] * $qty;
$total += $subtotal;
?>
<tr>
<td><?=h($food['name'])?></td>
<td>₹<?=number_format($food['price'],2)?></td>
<td><input type="number" name="qty[<?=$id?>]" value="<?=$qty?>" min="1" max="50"></td>
<td>₹<?=number_format($subtotal,2)?></td>
<td>
<button name="remove_cart" value="1" form="remove<?=$id?>">Remove</button>
</td>
</tr>
<?php endforeach; ?>
</table>

<h3>Total: ₹<?=number_format($total,2)?></h3>
<button name="update_cart">Update Cart</button>
</form>

<?php foreach($cart as $id=>$qty): ?>
<form method="POST" id="remove<?=(int)$id?>">
<input type="hidden" name="food_id" value="<?=(int)$id?>">
<input type="hidden" name="remove_cart" value="1">
</form>
<?php endforeach; ?>

<form method="POST" onsubmit="return confirmOrder()">
<button name="checkout">Place Order</button>
</form>

<?php } ?>

<?php elseif($page == 'orders'): ?>

<h2>My Orders</h2>

<table>
<tr>
<th>Order ID</th><th>Total</th><th>Status</th><th>Date</th>
</tr>

<?php
$sid = (int)$_SESSION['student_id'];
$stmt = $conn->prepare("SELECT * FROM orders WHERE student_id=? ORDER BY id DESC");
$stmt->bind_param("i",$sid);
$stmt->execute();
$result = $stmt->get_result();

while($order = $result->fetch_assoc()):
?>
<tr>
<td>#<?=$order['id']?></td>
<td>₹<?=number_format($order['total_amount'],2)?></td>
<td><?=h($order['status'])?></td>
<td><?=h($order['order_date'])?></td>
</tr>
<?php endwhile; ?>
</table>

<?php elseif($page == 'admin'): ?>

<h2>Admin Dashboard</h2>

<div class="grid">
<div class="card">
<h3>Students</h3>
<h2><?=$conn->query("SELECT id FROM students")->num_rows?></h2>
</div>
<div class="card">
<h3>Food Items</h3>
<h2><?=$conn->query("SELECT id FROM food_items WHERE availability=1")->num_rows?></h2>
</div>
<div class="card">
<h3>Total Orders</h3>
<h2><?=$conn->query("SELECT id FROM orders")->num_rows?></h2>
</div>
</div>

<br>
<h2>Add New Food</h2>
<div class="box">
<form method="POST">
<input type="text" name="name" placeholder="Food Name" required>
<textarea name="description" placeholder="Food Description"></textarea>
<input type="number" name="price" placeholder="Price" min="0.01" step="0.01" required>
<input type="text" name="category" placeholder="Category" required>
<button name="add_food">Add Food</button>
</form>
</div>

<h2>Manage Food Items</h2>
<table>
<tr><th>Name</th><th>Price</th><th>Category</th><th>Action</th></tr>
<?php
$foods = $conn->query("SELECT * FROM food_items WHERE availability=1");
while($food=$foods->fetch_assoc()):
?>
<tr>
<td><?=h($food['name'])?></td>
<td>₹<?=number_format($food['price'],2)?></td>
<td><?=h($food['category'])?></td>
<td>
<form method="POST">
<input type="hidden" name="food_id" value="<?=$food['id']?>">
<button name="delete_food">Remove</button>
</form>
</td>
</tr>
<?php endwhile; ?>
</table>

<br>
<h2>Manage Orders</h2>
<table>
<tr>
<th>ID</th><th>Student</th><th>Total</th><th>Status</th><th>Update</th>
</tr>
<?php
$orders = $conn->query(
"SELECT orders.*,students.name FROM orders
JOIN students ON orders.student_id=students.id
ORDER BY orders.id DESC"
);
while($order=$orders->fetch_assoc()):
?>
<tr>
<td>#<?=$order['id']?></td>
<td><?=h($order['name'])?></td>
<td>₹<?=number_format($order['total_amount'],2)?></td>
<td><?=h($order['status'])?></td>
<td>
<form method="POST">
<input type="hidden" name="order_id" value="<?=$order['id']?>">
<select name="status">
<?php foreach(['Pending','Preparing','Ready','Completed','Cancelled'] as $status): ?>
<option value="<?=$status?>" <?=$order['status']==$status?'selected':''?>>
<?=$status?>
</option>
<?php endforeach; ?>
</select>
<button name="update_status">Update</button>
</form>
</td>
</tr>
<?php endwhile; ?>
</table>

<?php endif; ?>

</main>

<footer>
<p>© 2026 College Canteen Order Management System</p>
</footer>

<script>
function searchFood() {
    let input = document.getElementById("search").value.toLowerCase();
    let cards = document.querySelectorAll(".food-card");

    cards.forEach(function(card) {
        card.style.display =
            card.innerText.toLowerCase().includes(input) ? "" : "none";
    });
}

function confirmOrder() {
    return confirm("Are you sure you want to place this order?");
}
</script>

</body>
</html>
