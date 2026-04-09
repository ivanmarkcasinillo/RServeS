
<?php

// ====================================================
// PHP with Folders to Organize Roles and Departments
//===================================================

session_start();
include __DIR__ . "/dbconnect.php";

$message = "";

// ---------------------- CONFIG ----------------------
$roleTables = [
    1 => ["table" => "students", "role" => "Student", "folder" => "student", "dashboard" => "student"],
    2 => ["table" => "administrators", "role" => "Administrator", "folder" => "admin", "dashboard" => "admin_dashboard.php"],
    3 => ["table" => "coordinator", "role" => "Coordinator", "folder" => "coordinator", "dashboard" => "coordinator"],
    4 => ["table" => "instructors", "role" => "Instructor", "folder" => "instructor", "dashboard" => "instructor"]
];

$departments = [
    1 => "College of Education",
    2 => "College of Technology",
    3 => "College of Hospitality and Tourism Management"
];

// ====================================================
// SIGN IN
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'signin') {
    $email = trim($_POST['signin_email']);
    $password = $_POST['signin_password'];
    $found = false;

    foreach ($roleTables as $roleId => $info) {
        $stmt = $conn->prepare("SELECT * FROM {$info['table']} WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            if (password_verify($password, $row['password'])) {
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $info['role'];
                $_SESSION['lastname'] = $row['lastname'];
                $_SESSION['firstname'] = $row['firstname'];
                $_SESSION['role_id'] = $roleId;
                $_SESSION['department_id'] = $row['department_id'];
                $_SESSION['logged_in'] = true;

                // ✅ Set primary ID depending on role
                switch ($info['role']) {
                    case "Student":
                        $_SESSION['stud_id'] = $row['stud_id'];
                        break;
                    case "Instructor":
                        $_SESSION['inst_id'] = $row['inst_id'];
                        break;
                    case "Coordinator":
                        $_SESSION['coor_id'] = $row['coor_id'];
                        break;
                    case "Administrator":
                        $_SESSION['adm_id'] = $row['adm_id'];
                        break;
                }

                // ✅ Redirect logic
                if ($info['role'] === "Administrator") {
                    $redirect = $info['folder'] . "/" . $info['dashboard'];
                } else {
                    $dept_code = strtolower(str_replace(" ", "_", $departments[$row['department_id']]));
                    $redirect = $info['folder'] . "/" . "{$info['dashboard']}_{$dept_code}_dashboard.php";
                }

                header("Location: " . $redirect);
                exit;
            }
        }
        $stmt->close();
    }

    $message = "❌ Invalid email or password.";
}

// ====================================================
// SIGN UP
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'signup') {
    $lastname   = trim($_POST['signup_lastname']);
    $firstname  = trim($_POST['signup_firstname']);
    $mi         = trim($_POST['signup_mi']);
    $email      = trim($_POST['signup_email']);
    $department = (int)$_POST['signup_department'];
    $role       = (int)$_POST['signup_role'];
    $password_raw = $_POST['signup_password'];

    if (!isset($roleTables[$role])) {
        $message = "❌ Invalid role selection.";
    } elseif (!isset($departments[$department])) {
        $message = "❌ Invalid department selection.";
    } else {
        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $table = $roleTables[$role]['table'];

        $stmt = $conn->prepare("INSERT INTO $table (lastname, firstname, mi, email, department_id, password) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssis", $lastname, $firstname, $mi, $email, $department, $password);

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $_SESSION['email'] = $email;
            $_SESSION['role'] = $roleTables[$role]['role'];
            $_SESSION['role_id'] = $role;
            $_SESSION['department_id'] = $department;
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['logged_in'] = true;

            // ✅ Assign ID by role
            switch ($roleTables[$role]['role']) {
                case "Student":
                    $_SESSION['stud_id'] = $newId;
                    header("Location: student/enrolment.php");
                    exit;
                case "Instructor":
                    $_SESSION['inst_id'] = $newId;
                    break;
                case "Coordinator":
                    $_SESSION['coor_id'] = $newId;
                    break;
                case "Administrator":
                    $_SESSION['adm_id'] = $newId;
                    break;
            }

            // Redirect others to their dashboards
            if ($roleTables[$role]['role'] !== "Student") {
                $dept_code = strtolower(str_replace(' ', '_', $departments[$department]));
                $redirect = $roleTables[$role]['folder'] . "/" . "{$roleTables[$role]['dashboard']}_{$dept_code}_dashboard.php";
                header("Location: " . $redirect);
                exit;
            }

        } else {
            $message = "❌ Registration failed: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSS - Portal V1</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="login.css">

<style>
body {
    font-family: Georgia, 'Times New Roman', Times, serif;
    background: url('img/bg.jpg') no-repeat center/cover;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    margin: 0;
}

.container {
    color:#a3c1ff;
    background: rgba(237, 244, 255, 0.57);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.4);
    width: 300px;
    text-align: center;
}

.container img {
    width: 80px;
    display: inline-block;
    vertical-align: middle;
}

h2 {
     color: #0b2f5eff;
    margin-top: 15px;
}

label, input, select, button {
    width: 100%;
    margin: 6px 0;
    padding: 8px;
    border-radius: 6px;
    border: none;
}

/* Password Toggle Styles */
.password-container {
    position: relative;
    width: 100%;
}

.password-container input {
    padding-right: 40px !important;
    box-sizing: border-box;
}

.password-toggle-icon {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #666;
    z-index: 10;
    font-size: 1.1rem;
}

button {
    background: #1a3555ff;
    color: white;
    font-weight: bold;
    cursor: pointer;
}
button:hover {
    background: #123f7bff;
}

.toggle {
    margin-top: 10px;
    color: #21407cff;
    cursor: pointer;
}
</style>
</head>
<body>
<div class="container">

<a class="navbar-brand fw-bold" href="#" style="
  display: inline-flex;
  align-items: baseline;
  text-decoration: none;
">
  <img src="logo3.png" alt="RServeS Logo" style="
    width: 50px;
    height: auto;
    margin-right: -12px;
    transform: translateY(4px);
    filter:blur(-8px);
  ">
  <span style="
    font-family: Georgia, 'Times New Roman', Times, serif;
    font-weight: 700;
    font-size: 1.5rem;
    color: #001055;
  ">RServeS</span>
</a>

<h2 id="form-title">Sign In</h2>

<?php if (!empty($message)) : ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form id="signin-form" method="POST">
    <input type="hidden" name="action" value="signin">
    <div class="form-group">
        <label>Email</label>
        <input type="text" name="signin_email" placeholder="School Email" required>
    </div>
    <div class="form-group">
        <label>Password</label>
        <div class="password-container">
            <input type="password" name="signin_password" placeholder="Enter Password" required id="signin_password">
            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signin_password', this)"></i>
        </div>
    </div>
    <button type="submit">Login</button>
</form>

<form id="signup-form" method="POST" style="display:none;" onsubmit="return validateSignup()">
    <input type="hidden" name="action" value="signup">
    <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="signup_lastname" required>
    </div>
    <div class="form-group">
        <label>First Name</label>
        <input type="text" name="signup_firstname" required>
    </div>
    <div class="form-group">
        <label>Middle Initial</label>
        <input type="text" name="signup_mi" maxlength="1" required>
    </div>
    <div class="form-group">
        <label>School Email</label>
        <input type="email" name="signup_email" required>
    </div>
    <div class="form-group">
        <label>Department</label>
        <select name="signup_department" required>
            <option value="">Select Department</option>
            <?php foreach ($departments as $id => $dept): ?>
                <option value="<?= $id ?>"><?= htmlspecialchars($dept) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Role</label>
        <select name="signup_role" required>
            <option value="">Select Role</option>
            <?php foreach ($roleTables as $id => $info): ?>
                <option value="<?= $id ?>"><?= htmlspecialchars($info['role']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Password</label>
        <div class="password-container">
            <input type="password" id="signup_password" name="signup_password" required>
            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signup_password', this)"></i>
        </div>
    </div>
    <div class="form-group">
        <label>Confirm Password</label>
        <div class="password-container">
            <input type="password" id="signup_confirm" name="signup_confirm" required>
            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signup_confirm', this)"></i>
        </div>
    </div>
    <button type="submit">Sign Up</button>
</form>

<div class="toggle" id="toggle-form">No account? Sign Up here</div>
</div>

<script>
const toggleForm = document.getElementById("toggle-form");
const signinForm = document.getElementById("signin-form");
const signupForm = document.getElementById("signup-form");
const formTitle = document.getElementById("form-title");

toggleForm.addEventListener("click", () => {
    if (signinForm.style.display === "none") {
        signinForm.style.display = "block";
        signupForm.style.display = "none";
        formTitle.textContent = "Sign In";
        toggleForm.textContent = "No account? Sign Up here";
    } else {
        signinForm.style.display = "none";
        signupForm.style.display = "block";
        formTitle.textContent = "Sign Up";
        toggleForm.textContent = "Already have an account? Sign In here";
    }
});

function togglePassword(passwordFieldId, icon) {
    const passwordField = document.getElementById(passwordFieldId);

    if (passwordField.type === "password") {
        passwordField.type = "text";
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    } else {
        passwordField.type = "password";
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    }
}

function validateSignup() {
    const password = document.getElementById("signup_password").value;
    const confirm = document.getElementById("signup_confirm").value;

    if (password !== confirm) {
        alert("❌ Passwords do not match. Please re-enter.");
        return false;
    }
    return true;
}
</script>



</body>
</html>
