<?php
// Load secure config
$config = include 'config.php';
// Step 1: Verify Turnstile (spam protection)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $turnstile_secret = $config['turnstile_secret'] ?? '';
    $turnstile_response = $_POST['cf-turnstile-response'] ?? '';

    if (empty($turnstile_response)) {
        echo json_encode(['status' => 'error', 'message' => 'Spam protection failed. Token missing.']);
        exit;
    }

    $turnstile_verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    $ch = curl_init($turnstile_verify_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $turnstile_secret,
        'response' => $turnstile_response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Prevent hangs

    $turnstile_result_raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($turnstile_result_raw === false || $http_code !== 200) {
        // Log for debugging (check your server's error log)
        error_log("Turnstile cURL failed: HTTP $http_code - $curl_error");
        echo json_encode(['status' => 'error', 'message' => 'Spam protection failed. Server connection issue.']);
        exit;
    }

    $turnstile_result = json_decode($turnstile_result_raw, true);

    if (!$turnstile_result || !$turnstile_result['success']) {
        // Log the actual error codes for debugging
        $error_codes = $turnstile_result['error-codes'] ?? ['unknown'];
        error_log("Turnstile failed: " . implode(', ', $error_codes));
        
        // You can customize messages based on codes if you want
        echo json_encode(['status' => 'error', 'message' => 'Spam protection failed. Please try again.']);
        exit;
    }

    // If here, Turnstile passed → continue to sanitize & send email
}

// Step 2: Sanitize inputs
$name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? '';
$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '';
$subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING) ?? '';
$property_address = filter_input(INPUT_POST, 'property-address', FILTER_SANITIZE_STRING) ?? '';
$message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING) ?? '';

// Validate required fields
if (empty($name) || empty($email) || empty($subject) || empty($property_address) || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Please fill in all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
    exit;
}

// Step 3: Set up email details
$to_email = 'service@discountcutsandservices.com';  // Replace with your preferred email
$from_email = 'service@discountcutsandservices.com';  // Your "from" address (can match $to_email)
$email_subject = "New Website message from {$name} - {$subject}";

$email_body = "
<html>
<body>
    <h2>New Contact Form Submission</h2>
    <p><strong>Name:</strong> {$name}</p>
    <p><strong>Email:</strong> {$email}</p>
    <p><strong>Property Address:</strong> {$property_address}</p>
    <p><strong>Message:</strong></p>
    <p>{$message}</p>
    <hr>
    <p><em>This email was sent via Discount Cuts & Services website on " . date('Y-m-d H:i:s') . "</em></p>
</body>
</html>
";

$headers = [
    'MIME-Version: 1.0',
    'Content-type: text/html; charset=UTF-8',
    'From: ' . $from_email,
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . phpversion()
];

// Step 4: Send via PHPMailer with SMTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/SMTP.php';
require 'PHPMailer/PHPMailer.php';

$mail = new PHPMailer(true);  // true = throw exceptions

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.hostinger.com';  // e.g., 'smtp.gmail.com' or your host's (ask host support)
    $mail->SMTPAuth   = true;
    $mail->Username   = 'service@discountcutsandservices.com';  // Usually your email
    $mail->Password   = $config['smtp_password'] ?? 'fallback-password';  // Fallback for safety
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;  // 'tls' or 'ssl'
    $mail->Port       = 587;  // 587 for TLS, 465 for SSL – check with host

    // Recipients
    $mail->setFrom($from_email, 'Discount Cuts & Services');
    $mail->addAddress($to_email);
    $mail->addReplyTo($email, $name);

    // Content
    $mail->isHTML(true);
    $mail->Subject = $email_subject;
    $mail->Body    = $email_body;

    $mail->send();
    echo json_encode(['status' => 'success', 'message' => 'Your message has been sent. Thank you!']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo]);
}
?>