<?php
// Load secure config
$config = include 'config.php';
// Step 1: Verify Turnstile (spam protection)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $turnstile_secret = $config['turnstile_secret'] ?? '';
    $turnstile_response = $_POST['cf-turnstile-response'] ?? '';

    $turnstile_verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $turnstile_data = [
        'secret' => $turnstile_secret,
        'response' => $turnstile_response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($turnstile_data)
        ]
    ];
    $context = stream_context_create($options);
    $turnstile_result = file_get_contents($turnstile_verify_url, false, $context);
    $turnstile_result = json_decode($turnstile_result, true);

    if (!$turnstile_result['success']) {
        echo json_encode(['status' => 'error', 'message' => 'Spam protection failed. Please try again.']);
        exit;
    }
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