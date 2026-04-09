<?php
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

$show_otp = false;
if (isset($_SESSION['show_otp_form']) && $_SESSION['show_otp_form']) {
    $show_otp = true;
}

$show_forgot = false;
if (isset($_SESSION['show_forgot_form'])) {
    $show_forgot = $_SESSION['show_forgot_form'];
}

// ====================================================
// FORGOT PASSWORD
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_email') {
    $email = trim($_POST['forgot_email']);
    $found = false;
    $found_user = null;
    $found_table = "";

    foreach ($roleTables as $roleId => $info) {
        $stmt = $conn->prepare("SELECT * FROM {$info['table']} WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $found = true;
            $found_user = $result->fetch_assoc();
            $found_table = $info['table'];
            break;
        }
        $stmt->close();
    }

    if ($found) {
        require_once "send_email.php";
        $otp = rand(100000, 999999);
        $subject = "RServeS Password Reset OTP";
        $body = "Your OTP for password reset is: $otp";
        
        $send_res = sendEmail($email, $found_user['firstname'] . " " . $found_user['lastname'], $subject, $body);

        if ($send_res === true) {
            $_SESSION['forgot_otp'] = $otp;
            $_SESSION['forgot_email'] = $email;
            $_SESSION['forgot_table'] = $found_table;
            $_SESSION['show_forgot_form'] = 'otp';
            header("Location: home2.php");
            exit;
        } else {
            $message = "❌ Error sending OTP: $send_res";
        }
    } else {
        $message = "❌ Email not found.";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_otp') {
    $submitted_otp = trim($_POST['forgot_otp_code']);
    if (isset($_SESSION['forgot_otp']) && $submitted_otp == $_SESSION['forgot_otp']) {
        $_SESSION['show_forgot_form'] = 'reset';
        header("Location: home2.php");
        exit;
    } else {
        $message = "❌ Invalid OTP.";
        $_SESSION['show_forgot_form'] = 'otp';
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_reset') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_new_password'];
    $email = $_SESSION['forgot_email'];
    $table = $_SESSION['forgot_table'];

    if ($new_password === $confirm_password) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE $table SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        if ($stmt->execute()) {
            $message = "✅ Password reset successful. You can now login.";
            unset($_SESSION['forgot_otp']);
            unset($_SESSION['forgot_email']);
            unset($_SESSION['forgot_table']);
            unset($_SESSION['show_forgot_form']);
        } else {
            $message = "❌ Error updating password.";
        }
        $stmt->close();
    } else {
        $message = "❌ Passwords do not match.";
        $_SESSION['show_forgot_form'] = 'reset';
    }
}

// ====================================================
// SIGN IN
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'signin') {
    $email = trim($_POST['signin_email']);
    $password = $_POST['signin_password'];
    $found = false;

    // Force error reporting for debugging 500 errors
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    foreach ($roleTables as $roleId => $info) {
        $stmt = $conn->prepare("SELECT * FROM {$info['table']} WHERE email = ?");
        
        // Safety Check: If table doesn't exist, prepare fails
        if (!$stmt) {
            error_log("Prepare failed for table {$info['table']}: " . $conn->error);
            continue; // Skip this role if table is missing
        }

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
                    // Safety: Default to 'technology' if dept_id is invalid/missing
                    $deptName = $departments[$row['department_id']] ?? 'College of Technology';
                    $dept_code = strtolower(str_replace(" ", "_", $deptName));
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
    $student_number = trim($_POST['signup_student_number']);
    $section    = trim($_POST['signup_section']);
    $department = (int)$_POST['signup_department'];
    $role       = 1; // Always Student
    $password_raw = $_POST['signup_password'];

    // 1. VALIDATE EMAIL DOMAIN
    if (!str_ends_with($email, '@llcc.edu.ph')) {
        $message = "❌ Invalid email. Only '@llcc.edu.ph' emails are allowed.";
    } else {
        // 2. GENERATE & SEND OTP
        require_once "send_email.php";
        $otp = rand(100000, 999999);
        $subject = "Your RServeS Verification Code";
        $body = "Your OTP for RServeS registration is: $otp";
        
        $send_res = sendEmail($email, "$firstname $lastname", $subject, $body);

        if ($send_res === true) {
            // 3. STORE DATA IN SESSION FOR VERIFICATION
            $_SESSION['otp'] = $otp;
            $_SESSION['signup_data'] = [
                'lastname' => $lastname,
                'firstname' => $firstname,
                'mi' => $mi,
                'student_number' => $student_number,
                'section' => $section,
                'email' => $email,
                'department' => $department,
                'password' => password_hash($password_raw, PASSWORD_DEFAULT)
            ];
            
            // 4. SET STATE TO SHOW OTP FORM
            $_SESSION['show_otp_form'] = true;
            header("Location: home2.php"); // Redirect to show OTP form
            exit;

        } else {
            $message = "❌ Could not send OTP. Please check your email and try again. Error: $send_res";
        }
    }
}

// ====================================================
// VERIFY OTP & COMPLETE SIGN UP
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'verify_otp') {
    $submitted_otp = trim($_POST['otp_code']);

    if (isset($_SESSION['otp']) && $submitted_otp == $_SESSION['otp']) {
        // OTP is correct, proceed with registration
        $data = $_SESSION['signup_data'];
        $table = $roleTables[1]['table']; // students table

        $stmt = $conn->prepare("INSERT INTO $table (lastname, firstname, mi, student_number, section, email, department_id, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssis", $data['lastname'], $data['firstname'], $data['mi'], $data['student_number'], $data['section'], $data['email'], $data['department'], $data['password']);

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            
            // ✅ Automatic Advisory Linking
            // Check if there's an instructor for this section and department
            $adv_stmt = $conn->prepare("SELECT instructor_id FROM section_advisers WHERE section = ? AND department_id = ? LIMIT 1");
            $adv_stmt->bind_param("si", $data['section'], $data['department']);
            $adv_stmt->execute();
            $adv_res = $adv_stmt->get_result();
            if ($adv_res && $adv_res->num_rows > 0) {
                $adv_row = $adv_res->fetch_assoc();
                $inst_id = $adv_row['instructor_id'];
                
                // Link student to this instructor
                $link_stmt = $conn->prepare("UPDATE students SET instructor_id = ? WHERE stud_id = ?");
                $link_stmt->bind_param("ii", $inst_id, $newId);
                $link_stmt->execute();
                $link_stmt->close();
            }
            $adv_stmt->close();

            $_SESSION['email'] = $data['email'];
            $_SESSION['role'] = 'Student';
            $_SESSION['role_id'] = 1;
            $_SESSION['department_id'] = $data['department'];
            $_SESSION['firstname'] = $data['firstname'];
            $_SESSION['lastname'] = $data['lastname'];
            $_SESSION['logged_in'] = true;
            $_SESSION['stud_id'] = $newId;

            // Clean up session
            unset($_SESSION['otp']);
            unset($_SESSION['signup_data']);
            unset($_SESSION['show_otp_form']);

            header("Location: student/enrolment.php");
            exit;
        } else {
            $message = "❌ Registration failed after OTP verification: " . $stmt->error;
        }
        $stmt->close();

    } else {
        $message = "❌ Invalid OTP. Please try again.";
        $_SESSION['show_otp_form'] = true; // Keep showing the form
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
    background: linear-gradient(rgba(13, 61, 97, 0.622), rgba(29, 110, 160, 0.526)), url('img/hi.png') no-repeat center/cover;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
    margin: 0;
    opacity: 0;
    animation: rservePageFadeIn 600ms ease forwards;
}

.container {
    color:#a3c1ff;
    background: rgba(237, 244, 255, 0.57);
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.4);
    width: 300px;
    text-align: center;
    opacity: 0;
    transform: translateY(10px) scale(0.99);
    animation: rserveCardIn 650ms cubic-bezier(0.2, 0.9, 0.2, 1) forwards;
    animation-delay: 80ms;
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

/* Password Container Styles */
.password-container {
    position: relative;
    width: 100%;
}

.password-container input {
    padding-right: 40px !important; /* Space for the icon */
    box-sizing: border-box;
    width: 100%;
    display: block;
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

.auth-form.rserve-anim-fade {
    animation: rserveFormFade 420ms ease both;
}

.auth-form.rserve-anim-slide-up {
    animation: rserveFormSlideUp 780ms cubic-bezier(0.12, 0.9, 0.12, 1) both;
}

.auth-form.rserve-anim-slide-down {
    animation: rserveFormSlideDown 520ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

@keyframes rservePageFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes rserveCardIn {
    0% { opacity: 0; transform: translateY(18px) scale(0.985); }
    60% { opacity: 1; transform: translateY(-4px) scale(1.01); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}

@keyframes rserveFormFade {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes rserveFormSlideUp {
    0% { opacity: 0; transform: translateY(26px); }
    65% { opacity: 1; transform: translateY(-18px); }
    100% { opacity: 1; transform: translateY(0); }
}

@keyframes rserveFormSlideDown {
    from { opacity: 0; transform: translateY(-18px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    body, .container { animation: none; opacity: 1; transform: none; }
    .auth-form { animation: none !important; }
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
  <img src="img/logo3.png" alt="RServeS Logo" style="
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

<h2 id="form-title">
    <?php
    if ($show_otp) {
        echo 'Verify Your Email';
    } elseif ($show_forgot === 'email') {
        echo 'Reset Password';
    } elseif ($show_forgot === 'otp') {
        echo 'Verify OTP';
    } elseif ($show_forgot === 'reset') {
        echo 'New Password';
    } else {
        echo 'Sign In';
    }
    ?>
</h2>

<?php if (!empty($message)) : ?>
    <div class="message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form id="signin-form" class="auth-form" method="POST" style="<?= ($show_otp || $show_forgot) ? 'display:none;' : '' ?>">
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
    <div style="margin-top: 10px; font-size: 0.8rem;">
        <a href="#" id="forgot-password-link" style="color: #0b2f5eff; text-decoration: none;">Forgot Password?</a>
    </div>
</form>

<form id="forgot-email-form" class="auth-form" method="POST" style="<?= ($show_forgot === 'email') ? 'display:block;' : 'display:none;' ?>">
    <input type="hidden" name="action" value="forgot_password_email">
    <p style="color: #0b2f5eff; font-size: 0.8rem; margin-bottom: 15px;">Enter your email to receive a password reset OTP.</p>
    <div class="form-group">
        <label>Email</label>
        <input type="email" name="forgot_email" placeholder="Enter your email" required>
    </div>
    <button type="submit">Send OTP</button>
    <div style="margin-top: 10px;">
        <a href="home2.php" id="back-to-login-link" style="color: #0b2f5eff; text-decoration: none; font-size: 0.8rem;">Back to Login</a>
    </div>
</form>

<form id="forgot-otp-form" class="auth-form" method="POST" style="<?= ($show_forgot === 'otp') ? 'display:block;' : 'display:none;' ?>">
    <input type="hidden" name="action" value="forgot_password_otp">
    <p style="color: #0b2f5eff; font-size: 0.8rem; margin-bottom: 15px;">Enter the 6-digit OTP sent to your email.</p>
    <div class="form-group">
        <label>OTP Code</label>
        <input type="text" name="forgot_otp_code" placeholder="Enter 6-digit OTP" required maxlength="6" pattern="\d{6}">
    </div>
    <button type="submit">Verify OTP</button>
</form>

<form id="forgot-reset-form" class="auth-form" method="POST" style="<?= ($show_forgot === 'reset') ? 'display:block;' : 'display:none;' ?>">
    <input type="hidden" name="action" value="forgot_password_reset">
    <div class="form-group">
        <label>New Password</label>
        <div class="password-container">
            <input type="password" id="new_password" name="new_password" required>
            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('new_password', this)"></i>
        </div>
    </div>
    <div class="form-group">
        <label>Confirm New Password</label>
        <div class="password-container">
            <input type="password" id="confirm_new_password" name="confirm_new_password" required>
            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('confirm_new_password', this)"></i>
        </div>
    </div>
    <button type="submit">Reset Password</button>
</form>

<form id="signup-form" class="auth-form" method="POST" style="display:none;" onsubmit="return validateSignup()">
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
        <label>Student ID</label>
        <input type="text" name="signup_student_number" placeholder="e.g. 2023-12345" required>
    </div>
    <div class="form-group">
        <label>Section</label>
        <input type="text" name="signup_section" placeholder="e.g. A, B, C" required>
    </div>
    <div class="form-group">
        <label>School Email</label>
        <input type="email" id="signup_email" name="signup_email" required>
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

<form id="otp-form" class="auth-form" method="POST" style="<?= $show_otp ? 'display:block;' : 'display:none;' ?>">
    <input type="hidden" name="action" value="verify_otp">
    <p style="color: #0b2f5eff; font-size: 0.9rem; margin-bottom: 15px;">An OTP has been sent to your school email. Please enter the 6-digit code below to verify your account.</p>
    <div class="form-group">
        <label>OTP Code</label>
        <input type="text" name="otp_code" placeholder="Enter 6-digit OTP" required maxlength="6" pattern="\d{6}">
    </div>
    <button type="submit">Verify & Register</button>
</form>

<div class="toggle" id="toggle-form" style="<?= $show_otp ? 'display:none;' : '' ?>">No account? Sign Up here</div>
</div>

<?php if ($show_otp) unset($_SESSION['show_otp_form']); ?>

<script>
const toggleForm = document.getElementById("toggle-form");
const signinForm = document.getElementById("signin-form");
const signupForm = document.getElementById("signup-form");
const forgotEmailForm = document.getElementById("forgot-email-form");
const forgotOtpForm = document.getElementById("forgot-otp-form");
const forgotResetForm = document.getElementById("forgot-reset-form");
const otpForm = document.getElementById("otp-form");
const formTitle = document.getElementById("form-title");
const forgotPasswordLink = document.getElementById("forgot-password-link");
const backToLoginLink = document.getElementById("back-to-login-link");

const allForms = [signinForm, signupForm, forgotEmailForm, forgotOtpForm, forgotResetForm, otpForm].filter(Boolean);

function setActiveForm(activeForm, opts) {
    const { title, toggleText, toggleVisible, animClass } = opts;

    allForms.forEach((f) => {
        f.classList.remove('rserve-anim-fade', 'rserve-anim-slide-up', 'rserve-anim-slide-down');
        f.style.display = "none";
    });

    if (activeForm) {
        activeForm.style.display = "block";
        activeForm.classList.remove('rserve-anim-fade', 'rserve-anim-slide-up', 'rserve-anim-slide-down');
        window.requestAnimationFrame(() => {
            if (animClass) activeForm.classList.add(animClass);
        });
    }

    if (formTitle && title) formTitle.textContent = title;

    if (toggleForm) {
        toggleForm.style.display = toggleVisible ? "" : "none";
        if (toggleText) toggleForm.textContent = toggleText;
    }
}

if (forgotPasswordLink) forgotPasswordLink.addEventListener("click", (e) => {
    e.preventDefault();
    setActiveForm(forgotEmailForm, {
        title: "Reset Password",
        toggleVisible: false,
        animClass: 'rserve-anim-fade'
    });
});

if (backToLoginLink) backToLoginLink.addEventListener("click", (e) => {
    e.preventDefault();
    setActiveForm(signinForm, {
        title: "Sign In",
        toggleText: "No account? Sign Up here",
        toggleVisible: true,
        animClass: 'rserve-anim-slide-down'
    });
});

if (toggleForm) toggleForm.addEventListener("click", () => {
    if (signinForm.style.display === "none") {
        setActiveForm(signinForm, {
            title: "Sign In",
            toggleText: "No account? Sign Up here",
            toggleVisible: true,
            animClass: 'rserve-anim-slide-down'
        });
    } else {
        setActiveForm(signupForm, {
            title: "Sign Up",
            toggleText: "Already have an account? Sign In here",
            toggleVisible: true,
            animClass: 'rserve-anim-slide-up'
        });
    }
});

const visibleForm = allForms.find((f) => f.style.display === "block");
if (visibleForm) {
    const inferredAnim =
        visibleForm === signupForm ? 'rserve-anim-slide-up' :
        (visibleForm === signinForm ? 'rserve-anim-slide-down' : 'rserve-anim-fade');
    window.requestAnimationFrame(() => {
        visibleForm.classList.add(inferredAnim);
    });
}

function validateSignup() {
    const password = document.getElementById("signup_password").value;
    const confirm = document.getElementById("signup_confirm").value;
    const email = document.getElementById("signup_email").value;

    if (!email.endsWith('@llcc.edu.ph')) {
        alert("❌ Please use your official school email (@llcc.edu.ph).");
        return false;
    }

    if (password !== confirm) {
        alert("❌ Passwords do not match. Please re-enter.");
        return false;
    }
    return true;
}

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
</script>



</body>
</html>
