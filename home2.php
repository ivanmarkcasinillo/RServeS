<?php
$rememberLifetime = 60 * 60 * 24 * 30;
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.gc_maxlifetime', (string)$rememberLifetime);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
include __DIR__ . "/dbconnect.php";

$message = "";
$messageType = "error";
$rememberedEmail = isset($_COOKIE['rserves_remember_email']) ? trim((string)$_COOKIE['rserves_remember_email']) : '';
$signinEmailValue = $_POST['signin_email'] ?? $rememberedEmail;
$rememberChecked = ($_SERVER["REQUEST_METHOD"] === "POST") ? isset($_POST['remember_me']) : ($rememberedEmail !== '');
$signupOtpLifetime = 5 * 60;

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
$show_signup = false;

if (!function_exists('setAuthFlashMessage')) {
    function setAuthFlashMessage($text, $type = 'error')
    {
        $_SESSION['auth_flash_message'] = $text;
        $_SESSION['auth_flash_message_type'] = $type;
    }
}

if (!function_exists('sendSignupVerificationOtp')) {
    function sendSignupVerificationOtp($signupData, $otpLifetime)
    {
        require_once __DIR__ . "/send_email.php";

        $otp = (string) random_int(100000, 999999);
        $minutes = (int) ceil($otpLifetime / 60);
        $subject = "Your RServeS Verification Code";
        $body = "Your OTP for RServeS registration is: {$otp}\n\nThis code will expire in {$minutes} minutes.";

        $sendRes = sendEmail($signupData['email'], trim($signupData['firstname'] . ' ' . $signupData['lastname']), $subject, $body);

        if ($sendRes === true) {
            $_SESSION['otp'] = $otp;
            $_SESSION['otp_expires_at'] = time() + (int) $otpLifetime;
            $_SESSION['show_otp_form'] = true;
            return true;
        }

        return $sendRes;
    }
}

if (isset($_SESSION['auth_flash_message'])) {
    $message = (string) $_SESSION['auth_flash_message'];
    $messageType = $_SESSION['auth_flash_message_type'] ?? 'error';
    unset($_SESSION['auth_flash_message'], $_SESSION['auth_flash_message_type']);
}

// ====================================================
// FORGOT PASSWORD
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_email') {
    $email = strtolower(trim($_POST['forgot_email']));
    $found = false;
    $found_user = null;
    $found_table = "";

    foreach ($roleTables as $roleId => $info) {
        $stmt = $conn->prepare("SELECT * FROM {$info['table']} WHERE LOWER(TRIM(email)) = ? LIMIT 1");
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
            $message = "Error sending OTP: $send_res";
            $show_forgot = 'email';
        }
    } else {
        $message = "Email not found.";
        $show_forgot = 'email';
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_otp') {
    $submitted_otp = trim($_POST['forgot_otp_code']);
    if (isset($_SESSION['forgot_otp']) && $submitted_otp == $_SESSION['forgot_otp']) {
        $_SESSION['show_forgot_form'] = 'reset';
        header("Location: home2.php");
        exit;
    } else {
        $message = "Invalid OTP.";
        $show_forgot = 'otp';
        $_SESSION['show_forgot_form'] = 'otp';
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'forgot_password_reset') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_new_password'];
    $email = strtolower(trim($_SESSION['forgot_email']));
    $table = $_SESSION['forgot_table'];

    if ($new_password === $confirm_password) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE $table SET password = ? WHERE LOWER(TRIM(email)) = ?");
        $stmt->bind_param("ss", $hashed_password, $email);
        if ($stmt->execute()) {
            $message = "Password reset successful. You can now sign in.";
            $messageType = "success";
            unset($_SESSION['forgot_otp']);
            unset($_SESSION['forgot_email']);
            unset($_SESSION['forgot_table']);
            unset($_SESSION['show_forgot_form']);
            $show_forgot = false;
        } else {
            $message = "Error updating password.";
            $show_forgot = 'reset';
        }
        $stmt->close();
    } else {
        $message = "Passwords do not match.";
        $show_forgot = 'reset';
        $_SESSION['show_forgot_form'] = 'reset';
    }
}

// ====================================================
// SIGN IN
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'signin') {
    $email = strtolower(trim($_POST['signin_email']));
    $password = $_POST['signin_password'];
    $rememberMe = isset($_POST['remember_me']);
    $found = false;

    foreach ($roleTables as $roleId => $info) {
        $stmt = $conn->prepare("SELECT * FROM {$info['table']} WHERE LOWER(TRIM(email)) = ? LIMIT 1");
        
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

            $storedPassword = (string)($row['password'] ?? '');
            $passwordOk = password_verify($password, $storedPassword);
            if (!$passwordOk) {
                $pwInfo = password_get_info($storedPassword);
                if (($pwInfo['algo'] ?? 0) === 0 && hash_equals($storedPassword, $password)) {
                    $passwordOk = true;
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $up = $conn->prepare("UPDATE {$info['table']} SET password = ? WHERE LOWER(TRIM(email)) = ?");
                    if ($up) {
                        $up->bind_param("ss", $newHash, $email);
                        $up->execute();
                        $up->close();
                    }
                }
            }

            if ($passwordOk) {
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $info['role'];
                $_SESSION['lastname'] = $row['lastname'];
                $_SESSION['firstname'] = $row['firstname'];
                $_SESSION['role_id'] = $roleId;
                $_SESSION['department_id'] = $row['department_id'];
                $_SESSION['logged_in'] = true;
                $_SESSION['remember_me'] = $rememberMe;

                $sessionCookieOptions = [
                    'expires' => $rememberMe ? time() + $rememberLifetime : 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ];

                setcookie(session_name(), session_id(), $sessionCookieOptions);

                if ($rememberMe) {
                    setcookie('rserves_remember_email', $email, [
                        'expires' => time() + $rememberLifetime,
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                } else {
                    setcookie('rserves_remember_email', '', [
                        'expires' => time() - 3600,
                        'path' => '/',
                        'domain' => '',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }

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

    $message = "Invalid email or password.";
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

    $send_res = sendSignupVerificationOtp($_SESSION['signup_data'], $signupOtpLifetime);

    if ($send_res === true) {
        setAuthFlashMessage("Verification code sent. It expires in 5 minutes.", "success");
        header("Location: home2.php");
        exit;
    } else {
        $message = "Could not send OTP. Please check your email and try again. Error: $send_res";
        $show_signup = true;
        unset($_SESSION['otp'], $_SESSION['otp_expires_at'], $_SESSION['show_otp_form']);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'resend_signup_otp') {
    if (!isset($_SESSION['signup_data']) || !is_array($_SESSION['signup_data'])) {
        $message = "Your verification session expired. Please sign up again.";
        $show_signup = true;
        unset($_SESSION['otp'], $_SESSION['otp_expires_at'], $_SESSION['show_otp_form']);
    } else {
        $send_res = sendSignupVerificationOtp($_SESSION['signup_data'], $signupOtpLifetime);

        if ($send_res === true) {
            setAuthFlashMessage("A new verification code has been sent. It expires in 5 minutes.", "success");
            header("Location: home2.php");
            exit;
        }

        $message = "Could not resend OTP. Please try again. Error: $send_res";
        $show_otp = true;
        $_SESSION['show_otp_form'] = true;
    }
}

// ====================================================
// VERIFY OTP & COMPLETE SIGN UP
// ====================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $_POST['action'] === 'verify_otp') {
    $submitted_otp = trim($_POST['otp_code']);
    $storedOtp = isset($_SESSION['otp']) ? (string) $_SESSION['otp'] : '';
    $otpExpiresAt = isset($_SESSION['otp_expires_at']) ? (int) $_SESSION['otp_expires_at'] : 0;

    if (!isset($_SESSION['signup_data']) || !is_array($_SESSION['signup_data'])) {
        $message = "Your verification session expired. Please sign up again.";
        $show_signup = true;
        unset($_SESSION['otp'], $_SESSION['otp_expires_at'], $_SESSION['show_otp_form']);
    } elseif ($storedOtp === '' || $otpExpiresAt <= 0 || $otpExpiresAt < time()) {
        $message = "This verification code has expired. Please resend OTP.";
        $show_otp = true;
        $_SESSION['show_otp_form'] = true;
        unset($_SESSION['otp'], $_SESSION['otp_expires_at']);
    } elseif (hash_equals($storedOtp, $submitted_otp)) {
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
            unset($_SESSION['otp_expires_at']);
            unset($_SESSION['signup_data']);
            unset($_SESSION['show_otp_form']);

            header("Location: student/enrolment.php");
            exit;
        } else {
            $message = "Registration failed after OTP verification: " . $stmt->error;
            $show_otp = true;
        }
        $stmt->close();

    } else {
        $message = "Invalid OTP. Please try again.";
        $show_otp = true;
        $_SESSION['show_otp_form'] = true; // Keep showing the form
    }
}

$signupOtpExpiresAt = isset($_SESSION['otp_expires_at']) ? (int) $_SESSION['otp_expires_at'] : 0;
$signupOtpSecondsRemaining = max(0, $signupOtpExpiresAt - time());
$signupOtpCountdownLabel = $signupOtpSecondsRemaining > 0 ? gmdate('i:s', $signupOtpSecondsRemaining) : 'Expired';

$activeView = 'signin';
$formTitle = 'Sign In';
$formSubtitle = 'Please enter your institutional credentials to continue.';
$showAuthSwitch = true;
$authSwitchPrompt = "Don't have an account?";
$authSwitchText = 'Sign up here';

if ($show_otp) {
    $activeView = 'otp';
    $formTitle = 'Verify Your Email';
    $formSubtitle = 'We sent a 6-digit verification code to your school email. It stays valid for 5 minutes.';
    $showAuthSwitch = false;
} elseif ($show_forgot === 'email') {
    $activeView = 'forgot_email';
    $formTitle = 'Reset Password';
    $formSubtitle = 'Enter your institutional email to receive a one-time verification code.';
    $showAuthSwitch = false;
} elseif ($show_forgot === 'otp') {
    $activeView = 'forgot_otp';
    $formTitle = 'Verify OTP';
    $formSubtitle = 'Enter the 6-digit code sent to your email to continue the password reset.';
    $showAuthSwitch = false;
} elseif ($show_forgot === 'reset') {
    $activeView = 'forgot_reset';
    $formTitle = 'Create New Password';
    $formSubtitle = 'Set a new password for your account and sign back in securely.';
    $showAuthSwitch = false;
} elseif ($show_signup) {
    $activeView = 'signup';
    $formTitle = 'Create Account';
    $formSubtitle = 'Set up your student portal account to manage Return of Service requirements.';
    $authSwitchPrompt = 'Already have an account?';
    $authSwitchText = 'Sign in here';
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RServeS Portal Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
:root{--navy-950:#082746;--navy-900:#0d355f;--navy-800:#174d84;--navy-700:#28629d;--ink-900:#0f1728;--ink-700:#47556c;--ink-500:#748298;--border-soft:rgba(15,23,40,.1);--shadow-soft:0 26px 70px rgba(10,33,60,.12);--shadow-button:0 18px 34px rgba(13,53,95,.22)}
*{box-sizing:border-box}
html,body{min-height:100%}
body{margin:0;font-family:'Manrope',sans-serif;color:var(--ink-900);background:radial-gradient(circle at top left,rgba(34,96,160,.10),transparent 28%),linear-gradient(180deg,#edf2f8 0%,#f7f8fb 100%);opacity:0;animation:pageFadeIn .45s ease forwards}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
.auth-shell{min-height:100vh;padding:40px 24px 28px;display:flex;flex-direction:column;justify-content:center}
.auth-card{width:min(1440px,100%);margin:0 auto;display:grid;grid-template-columns:minmax(380px,1.05fr) minmax(420px,.95fr);background:rgba(255,255,255,.88);border:1px solid rgba(255,255,255,.7);border-radius:30px;overflow:hidden;box-shadow:var(--shadow-soft);backdrop-filter:blur(12px);transform:translateY(16px);animation:cardRise .65s cubic-bezier(.2,.9,.2,1) forwards}
.auth-hero{position:relative;min-height:720px;padding:44px 46px;display:flex;flex-direction:column;justify-content:space-between;color:#fff;background:linear-gradient(180deg,rgba(6,35,66,.30) 0%,rgba(6,35,66,.72) 100%),linear-gradient(140deg,rgba(8,39,70,.92) 0%,rgba(16,76,133,.82) 100%),url('img/bg.jpg') center center/cover no-repeat}
.auth-hero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at top right,rgba(120,183,255,.20),transparent 30%),radial-gradient(circle at bottom left,rgba(120,183,255,.10),transparent 34%)}
.auth-hero>*{position:relative;z-index:1}
.hero-brand{display:inline-flex;align-items:center;gap:.9rem}
.hero-brand-logo{width:58px;height:58px;object-fit:contain;padding:.5rem;border-radius:18px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.16);box-shadow:inset 0 1px 0 rgba(255,255,255,.12)}
.hero-brand-text{display:inline-flex;flex-direction:column;gap:.35rem}
.hero-brand-text strong{font-family:'Sora',sans-serif;font-size:1.7rem;font-weight:700;letter-spacing:-.05em}
.hero-brand-text span{width:58px;height:4px;border-radius:999px;background:linear-gradient(90deg,#2aa3ff 0%,#75c0ff 100%)}
.hero-copy{max-width:480px}
.hero-copy h1{margin:0 0 1.35rem;font-family:'Sora',sans-serif;font-size:clamp(3.2rem,5vw,5.5rem);line-height:.92;letter-spacing:-.08em;font-weight:800;text-wrap:balance}
.hero-copy p{margin:0;max-width:440px;color:rgba(222,235,255,.82);font-size:1.15rem;line-height:1.8}
.hero-badge{display:inline-flex;align-items:center;gap:.9rem;max-width:440px;padding:1.05rem 1.25rem;border-radius:20px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.12);box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}
.hero-badge i{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border-radius:14px;color:#8fd1ff;background:rgba(19,103,175,.30)}
.hero-badge span{font-weight:700;font-size:1rem;letter-spacing:.01em}
.auth-panel{background:rgba(255,255,255,.96);padding:56px 62px 46px;display:flex;flex-direction:column;justify-content:center}
.auth-panel-header{margin-bottom:1.9rem}
.auth-panel-header h2{margin:0 0 .85rem;font-family:'Sora',sans-serif;font-size:clamp(2.35rem,3vw,3.35rem);line-height:1;letter-spacing:-.07em;font-weight:700}
.auth-panel-header p{margin:0;max-width:430px;color:var(--ink-700);font-size:1.08rem;line-height:1.65}
.message{margin-bottom:1.2rem;padding:.9rem 1rem;border-radius:16px;font-size:.95rem;line-height:1.55;border:1px solid transparent}
.message--success{color:#14532d;background:#ecfdf3;border-color:#b7efcb}
.message--error{color:#8a1c1c;background:#fef0f0;border-color:#f7c5c5}
.auth-form{width:100%}
.auth-form.rserve-anim-fade{animation:formFade .32s ease both}
.auth-form.rserve-anim-slide-up{animation:formSlideUp .42s ease both}
.auth-form.rserve-anim-slide-down{animation:formSlideDown .36s ease both}
.form-group{margin-bottom:1.15rem}
.form-group label,.form-row-label{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.55rem;color:var(--ink-900);font-size:.96rem;font-weight:700;letter-spacing:.01em}
.field-control,.password-container input,.form-group select{width:100%;min-height:62px;padding:.98rem 1rem;border-radius:18px;border:1px solid var(--border-soft);background:#fff;color:var(--ink-900);transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease}
.field-control::placeholder,.password-container input::placeholder{color:#8d99ad}
.field-control:hover,.password-container input:hover,.form-group select:hover{transform:translateY(-1px)}
.field-control:focus,.password-container input:focus,.form-group select:focus{outline:none;border-color:rgba(23,77,132,.45);box-shadow:0 0 0 4px rgba(39,99,168,.12)}
.password-container{position:relative}
.password-container input{padding-right:3.1rem}
.password-toggle-icon{position:absolute;right:1rem;top:50%;transform:translateY(-50%);color:#9aa6ba;cursor:pointer;transition:color .2s ease}
.password-toggle-icon:hover{color:var(--navy-900)}
.form-options{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin:.25rem 0 1.45rem}
.remember-field{display:inline-flex;align-items:center;gap:.7rem;color:var(--ink-700);font-size:.95rem;cursor:pointer}
.remember-field input{width:18px;height:18px;margin:0;accent-color:var(--navy-800)}
.text-link{color:var(--navy-800);font-size:.94rem;font-weight:700;letter-spacing:.03em}
.text-link:hover{color:var(--navy-900)}
.primary-button{width:100%;min-height:64px;border:0;border-radius:18px;background:linear-gradient(135deg,var(--navy-900) 0%,var(--navy-700) 100%);color:#fff;font-size:1rem;font-weight:800;letter-spacing:-.02em;cursor:pointer;box-shadow:var(--shadow-button);transition:transform .2s ease,box-shadow .2s ease,filter .2s ease}
.primary-button:hover{transform:translateY(-1px);box-shadow:0 20px 36px rgba(13,53,95,.28);filter:brightness(1.02)}
.secondary-button{display:inline-flex;align-items:center;justify-content:center;min-height:50px;padding:.85rem 1.2rem;border-radius:16px;border:1px solid rgba(23,77,132,.16);background:#f5f8fc;color:var(--navy-900);font-size:.95rem;font-weight:800;cursor:pointer;transition:transform .2s ease,border-color .2s ease,background-color .2s ease}
.secondary-button:hover{transform:translateY(-1px);border-color:rgba(23,77,132,.28);background:#edf4fb}
.auth-switch{margin-top:1.6rem;text-align:center;color:var(--ink-700);font-size:.96rem}
.auth-switch button{border:0;background:transparent;color:var(--navy-800);font-weight:800;cursor:pointer;padding:0;margin-left:.35rem}
.auth-switch button:hover{color:var(--navy-900)}
.auth-footer{width:min(1440px,100%);margin:18px auto 0;display:flex;flex-wrap:wrap;justify-content:space-between;gap:.75rem 1.5rem;color:var(--ink-500);font-size:.85rem;letter-spacing:.18em;text-transform:uppercase}
.auth-footer-links{display:flex;flex-wrap:wrap;gap:1.5rem}
.auth-footer-links a:hover{color:var(--navy-900)}
.signup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 1rem}
.signup-grid .form-group--full{grid-column:1/-1}
.auth-note{margin:0 0 1.25rem;color:var(--ink-700);font-size:.95rem;line-height:1.65}
.otp-meta{display:flex;align-items:center;gap:.8rem;flex-wrap:wrap;margin:-.35rem 0 1.2rem}
.countdown-pill{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem .8rem;border-radius:999px;background:#edf4fb;color:var(--navy-900);font-size:.9rem;font-weight:800}
.countdown-pill i{color:var(--navy-700)}
.otp-meta-text{color:var(--ink-700);font-size:.92rem}
.otp-resend-form{margin-top:.95rem;display:flex;align-items:center;gap:.85rem;flex-wrap:wrap}
.otp-resend-form span{color:var(--ink-700);font-size:.93rem}
@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
@keyframes cardRise{0%{opacity:0;transform:translateY(24px) scale(.985)}100%{opacity:1;transform:translateY(0) scale(1)}}
@keyframes formFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes formSlideUp{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
@keyframes formSlideDown{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:translateY(0)}}
@media (max-width:1180px){.auth-card{grid-template-columns:minmax(320px,.9fr) minmax(420px,1.1fr)}.auth-panel{padding:48px 42px 40px}}
@media (max-width:991.98px){.auth-shell{padding:20px 16px}.auth-card{grid-template-columns:1fr}.auth-hero{min-height:420px;padding:34px 28px}.auth-panel{padding:34px 24px 30px}}
@media (max-width:767.98px){.auth-hero{min-height:360px}.hero-copy h1{font-size:3.05rem}.signup-grid{grid-template-columns:1fr}.form-options,.auth-footer,.otp-resend-form{flex-direction:column;align-items:flex-start}.auth-footer-links{gap:.85rem 1.2rem}.secondary-button{width:100%}}
@media (prefers-reduced-motion:reduce){body,.auth-card,.auth-form{animation:none!important;opacity:1;transform:none}}
</style>
</head>
<body>
<div class="auth-shell">
    <main class="auth-card">
        <section class="auth-hero" aria-hidden="true">
            <div class="hero-brand">
                <img src="img/logo3.png" alt="" class="hero-brand-logo" onerror="this.src='img/logo.png'">
                <div class="hero-brand-text">
                    <strong>RServeS</strong>
                    <span></span>
                </div>
            </div>

            <div class="hero-copy">
                <h1>Securing the Future of Institutional Service.</h1>
                <p>Manage your Return of Service agreements with precision, clarity, and academic integrity.</p>
            </div>

            <div class="hero-badge">
                <i class="fas fa-shield-alt"></i>
                <span>Enterprise-grade security standard</span>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-panel-header">
                <h2 id="form-title"><?= htmlspecialchars($formTitle) ?></h2>
                <p id="form-subtitle"><?= htmlspecialchars($formSubtitle) ?></p>
            </div>

            <?php if (!empty($message)) : ?>
                <div class="message <?= $messageType === 'success' ? 'message--success' : 'message--error' ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form id="signin-form" class="auth-form" method="POST" style="<?= $activeView === 'signin' ? 'display:block;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="signin">
                <div class="form-group">
                    <label for="signin_email">Institutional Email</label>
                    <input class="field-control" type="email" id="signin_email" name="signin_email" placeholder="name@institution.edu" value="<?= htmlspecialchars((string)$signinEmailValue) ?>" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <div class="form-row-label">
                        <label for="signin_password">Password</label>
                        <a href="#" id="forgot-password-link" class="text-link">Forgot Password?</a>
                    </div>
                    <div class="password-container">
                        <input type="password" id="signin_password" name="signin_password" placeholder="Enter your password" autocomplete="current-password" required>
                        <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signin_password', this)"></i>
                    </div>
                </div>
                <div class="form-options">
            <label class="remember-field" for="remember_me">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" <?= $rememberChecked ? 'checked' : '' ?>>
                        <span>Remember my email (30 days)</span>
                    </label>
                </div>
                <button type="submit" class="primary-button">Sign In</button>
            </form>

            <form id="forgot-email-form" class="auth-form" method="POST" style="<?= $activeView === 'forgot_email' ? 'display:block;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="forgot_password_email">
                <p class="auth-note">Enter your institutional email to receive a password reset verification code.</p>
                <div class="form-group">
                    <label for="forgot_email">Institutional Email</label>
                    <input class="field-control" type="email" id="forgot_email" name="forgot_email" placeholder="name@institution.edu" value="<?= htmlspecialchars($_POST['forgot_email'] ?? $rememberedEmail) ?>" autocomplete="email" required>
                </div>
                <button type="submit" class="primary-button">Send OTP</button>
                <div class="auth-switch" style="display:block;">
                    <span>Remembered your password?</span>
                    <button type="button" id="back-to-login-link">Back to sign in</button>
                </div>
            </form>

            <form id="forgot-otp-form" class="auth-form" method="POST" style="<?= $activeView === 'forgot_otp' ? 'display:block;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="forgot_password_otp">
                <p class="auth-note">Enter the 6-digit OTP sent to your email.</p>
                <div class="form-group">
                    <label for="forgot_otp_code">OTP Code</label>
                    <input class="field-control" type="text" id="forgot_otp_code" name="forgot_otp_code" placeholder="Enter 6-digit OTP" maxlength="6" pattern="\d{6}" inputmode="numeric" required>
                </div>
                <button type="submit" class="primary-button">Verify OTP</button>
            </form>

            <form id="forgot-reset-form" class="auth-form" method="POST" style="<?= $activeView === 'forgot_reset' ? 'display:block;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="forgot_password_reset">
                <p class="auth-note">Choose a strong new password for your account.</p>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-container">
                        <input type="password" id="new_password" name="new_password" placeholder="Enter new password" autocomplete="new-password" required>
                        <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('new_password', this)"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_new_password">Confirm New Password</label>
                    <div class="password-container">
                        <input type="password" id="confirm_new_password" name="confirm_new_password" placeholder="Re-enter new password" autocomplete="new-password" required>
                        <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('confirm_new_password', this)"></i>
                    </div>
                </div>
                <button type="submit" class="primary-button">Reset Password</button>
            </form>

            <form id="signup-form" class="auth-form" method="POST" style="<?= $activeView === 'signup' ? 'display:block;' : 'display:none;' ?>" onsubmit="return validateSignup()">
                <input type="hidden" name="action" value="signup">
                <div class="signup-grid">
                    <div class="form-group">
                        <label for="signup_lastname">Last Name</label>
                        <input class="field-control" type="text" id="signup_lastname" name="signup_lastname" value="<?= htmlspecialchars($_POST['signup_lastname'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="signup_firstname">First Name</label>
                        <input class="field-control" type="text" id="signup_firstname" name="signup_firstname" value="<?= htmlspecialchars($_POST['signup_firstname'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="signup_mi">Middle Initial</label>
                        <input class="field-control" type="text" id="signup_mi" name="signup_mi" value="<?= htmlspecialchars($_POST['signup_mi'] ?? '') ?>" maxlength="1" required>
                    </div>
                    <div class="form-group">
                        <label for="signup_student_number">Student ID</label>
                        <input class="field-control" type="text" id="signup_student_number" name="signup_student_number" placeholder="e.g. 2023-12345" value="<?= htmlspecialchars($_POST['signup_student_number'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="signup_section">Section</label>
                        <input class="field-control" type="text" id="signup_section" name="signup_section" placeholder="e.g. A, B, C" value="<?= htmlspecialchars($_POST['signup_section'] ?? '') ?>" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label for="signup_email">School Email</label>
                        <input class="field-control" type="email" id="signup_email" name="signup_email" placeholder="name@institution.edu" value="<?= htmlspecialchars($_POST['signup_email'] ?? '') ?>" autocomplete="email" required>
                    </div>
                    <div class="form-group form-group--full">
                        <label for="signup_department">Department</label>
                        <select id="signup_department" name="signup_department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $id => $dept): ?>
                                <option value="<?= $id ?>" <?= ((string)($id) === (string)($_POST['signup_department'] ?? '')) ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="signup_password">Password</label>
                        <div class="password-container">
                            <input type="password" id="signup_password" name="signup_password" placeholder="Create password" autocomplete="new-password" required>
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signup_password', this)"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="signup_confirm">Confirm Password</label>
                        <div class="password-container">
                            <input type="password" id="signup_confirm" name="signup_confirm" placeholder="Repeat password" autocomplete="new-password" required>
                            <i class="fas fa-eye-slash password-toggle-icon" onclick="togglePassword('signup_confirm', this)"></i>
                        </div>
                    </div>
                </div>
                <button type="submit" class="primary-button">Create Account</button>
            </form>

            <form id="otp-form" class="auth-form" method="POST" style="<?= $activeView === 'otp' ? 'display:block;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="verify_otp">
                <p class="auth-note">An OTP has been sent to your school email. Enter the 6-digit code below to verify your account.</p>
                <div class="otp-meta">
                    <span class="countdown-pill">
                        <i class="fas fa-clock"></i>
                        <span id="signup-otp-countdown" data-expiry="<?= $signupOtpExpiresAt ?>"><?= htmlspecialchars($signupOtpCountdownLabel) ?></span>
                    </span>
                    <span class="otp-meta-text">Valid for 5 minutes from the latest send.</span>
                </div>
                <div class="form-group">
                    <label for="otp_code">OTP Code</label>
                    <input class="field-control" type="text" id="otp_code" name="otp_code" placeholder="Enter 6-digit OTP" maxlength="6" pattern="\d{6}" inputmode="numeric" required>
                </div>
                <button type="submit" class="primary-button">Verify and Register</button>
            </form>

            <form id="resend-otp-form" class="otp-resend-form" method="POST" style="<?= $activeView === 'otp' ? 'display:flex;' : 'display:none;' ?>">
                <input type="hidden" name="action" value="resend_signup_otp">
                <span>Didn't get the email or need a new code?</span>
                <button type="submit" class="secondary-button">Resend OTP</button>
            </form>

            <div class="auth-switch" id="auth-switch" style="<?= $showAuthSwitch ? 'display:block;' : 'display:none;' ?>">
                <span id="auth-switch-prompt"><?= htmlspecialchars($authSwitchPrompt) ?></span>
                <button type="button" id="toggle-form"><?= htmlspecialchars($authSwitchText) ?></button>
            </div>
        </section>
    </main>

    <footer class="auth-footer">
        <span>&copy; 2026 RServeS Institutional Management. All rights reserved.</span>
<div class="auth-footer-links">
            <a href="index.html">← Back Home</a>
            <a href="#">Privacy Policy</a>
            <a href="#">Security Standards</a>
        </div>
    </footer>
</div>

<?php if ($show_otp) unset($_SESSION['show_otp_form']); ?>

<script>
const toggleForm = document.getElementById("toggle-form");
const authSwitch = document.getElementById("auth-switch");
const authSwitchPrompt = document.getElementById("auth-switch-prompt");
const signinForm = document.getElementById("signin-form");
const signupForm = document.getElementById("signup-form");
const forgotEmailForm = document.getElementById("forgot-email-form");
const forgotOtpForm = document.getElementById("forgot-otp-form");
const forgotResetForm = document.getElementById("forgot-reset-form");
const otpForm = document.getElementById("otp-form");
const formTitle = document.getElementById("form-title");
const formSubtitle = document.getElementById("form-subtitle");
const forgotPasswordLink = document.getElementById("forgot-password-link");
const backToLoginLink = document.getElementById("back-to-login-link");

const allForms = [signinForm, signupForm, forgotEmailForm, forgotOtpForm, forgotResetForm, otpForm].filter(Boolean);

function setActiveForm(activeForm, opts) {
    const {
        title,
        subtitle,
        switchPrompt,
        switchText,
        switchVisible,
        animClass
    } = opts;

    allForms.forEach((form) => {
        form.classList.remove('rserve-anim-fade', 'rserve-anim-slide-up', 'rserve-anim-slide-down');
        form.style.display = "none";
    });

    if (activeForm) {
        activeForm.style.display = "block";
        window.requestAnimationFrame(() => {
            if (animClass) activeForm.classList.add(animClass);
        });
    }

    if (formTitle) formTitle.textContent = title || "";
    if (formSubtitle) formSubtitle.textContent = subtitle || "";

    if (authSwitch) {
        authSwitch.style.display = switchVisible ? "block" : "none";
    }

    if (authSwitchPrompt && switchPrompt) {
        authSwitchPrompt.textContent = switchPrompt;
    }

    if (toggleForm && switchText) {
        toggleForm.textContent = switchText;
    }
}

if (forgotPasswordLink) forgotPasswordLink.addEventListener("click", (e) => {
    e.preventDefault();
    setActiveForm(forgotEmailForm, {
        title: "Reset Password",
        subtitle: "Enter your institutional email to receive a one-time verification code.",
        switchVisible: false,
        animClass: 'rserve-anim-fade'
    });
});

if (backToLoginLink) backToLoginLink.addEventListener("click", (e) => {
    e.preventDefault();
    setActiveForm(signinForm, {
        title: "Sign In",
        subtitle: "Please enter your institutional credentials to continue.",
        switchPrompt: "Don't have an account?",
        switchText: "Sign up here",
        switchVisible: true,
        animClass: 'rserve-anim-slide-down'
    });
});

if (toggleForm) toggleForm.addEventListener("click", () => {
    const showingSignIn = signinForm && signinForm.style.display !== "none";

    if (showingSignIn) {
        setActiveForm(signupForm, {
            title: "Create Account",
            subtitle: "Set up your student portal account to manage Return of Service requirements.",
            switchPrompt: "Already have an account?",
            switchText: "Sign in here",
            switchVisible: true,
            animClass: 'rserve-anim-slide-up'
        });
    } else {
        setActiveForm(signinForm, {
            title: "Sign In",
            subtitle: "Please enter your institutional credentials to continue.",
            switchPrompt: "Don't have an account?",
            switchText: "Sign up here",
            switchVisible: true,
            animClass: 'rserve-anim-slide-down'
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

    if (password !== confirm) {
        alert("Passwords do not match. Please re-enter them.");
        return false;
    }
    return true;
}

function togglePassword(passwordFieldId, icon) {
    const passwordField = document.getElementById(passwordFieldId);

    if (!passwordField) return;

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

const signupOtpCountdown = document.getElementById("signup-otp-countdown");

if (signupOtpCountdown) {
    const expirySeconds = Number(signupOtpCountdown.dataset.expiry || 0);

    if (expirySeconds > 0) {
        const updateSignupOtpCountdown = () => {
            const remainingSeconds = Math.max(0, expirySeconds - Math.floor(Date.now() / 1000));

            if (remainingSeconds <= 0) {
                signupOtpCountdown.textContent = "Expired";
                return false;
            }

            const minutes = String(Math.floor(remainingSeconds / 60)).padStart(2, "0");
            const seconds = String(remainingSeconds % 60).padStart(2, "0");
            signupOtpCountdown.textContent = `${minutes}:${seconds}`;
            return true;
        };

        const shouldContinue = updateSignupOtpCountdown();
        if (shouldContinue) {
            const countdownTimer = window.setInterval(() => {
                if (!updateSignupOtpCountdown()) {
                    window.clearInterval(countdownTimer);
                }
            }, 1000);
        }
    }
}

</script>



</body>
</html>
