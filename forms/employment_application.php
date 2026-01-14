<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load .env from root (one level up from /forms/)
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$debug = $_ENV['DEBUG_MODE'] ?? false;

// Init vars from env
$smtpHost = $_ENV['SMTP_HOST'] ?? '';
$smtpPort = (int) ($_ENV['SMTP_PORT'] ?? 587);
$smtpUser = $_ENV['SMTP_USER'] ?? '';
$smtpPass = $_ENV['SMTP_PASS'] ?? '';
$fromName = $_ENV['SMTP_FROM_NAME'] ?? 'Discount Cuts';
$fromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? '';
$adminEmail = $_ENV['ADMIN_EMAIL'] ?? '';
$turnstileSecret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
$siteUrl = $_ENV['SITE_URL'] ?? 'http://localhost:8080/discountcutsandservices';  // Local test; update for prod
$pdfTemplate = __DIR__ . '/application_template.pdf';  // Your committed PDF

// Honeypot check
if (!empty($_POST['honeypot'] ?? '')) {
    header('Location: ' . $siteUrl . '/hiring.html?error=spam');
    exit;
}

// Only process POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $siteUrl . '/hiring.html');
    exit;
}

// Turnstile validation (cURL—ensure php_curl enabled in XAMPP php.ini)
$turnstileResponse = $_POST['cf-turnstile-response'] ?? '';
$turnstileValid = false;
if ($turnstileResponse && $turnstileSecret) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $turnstileSecret,
        'response' => $turnstileResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_error($ch)) {
        if ($debug) error_log('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);
    $result = json_decode($response, true);
    $turnstileValid = $result['success'] ?? false;
}

if (!$turnstileValid) {
    header('Location: ' . $siteUrl . '/hiring.html?error=turnstile');
    exit;
}

// Pull & sanitize all fields (matches hiring.html)
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$street_address = trim($_POST['street_address'] ?? '');
$years_experience = (float) ($_POST['years_experience'] ?? 0);
$desired_pay = (float) ($_POST['desired_pay'] ?? 0);
$drivers_license = !empty($_POST['drivers_license'] ?? '');
$reliable_transport = !empty($_POST['reliable_transport'] ?? '');
$cover_letter = trim($_POST['cover_letter'] ?? '');
$agreement = !empty($_POST['agreement'] ?? '');
$application_date = trim($_POST['application_date'] ?? date('Y-m-d'));
$printed_name = trim($_POST['printed_name'] ?? '');

// Server-side validation (mirrors client-side)
$errors = [];
if (empty($full_name) || strlen($full_name) < 2) $errors[] = 'full_name';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if (!empty($phone) && !preg_match('/^\d{3}-\d{3}-\d{4}$/', $phone)) $errors[] = 'phone';
if (empty($street_address)) $errors[] = 'street_address';
if ($years_experience < 0) $errors[] = 'years_experience';
if ($desired_pay < 0) $errors[] = 'desired_pay';
if (strlen($cover_letter) < 50) $errors[] = 'cover_letter';
if (!$agreement) $errors[] = 'agreement';
if (empty($application_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $application_date)) $errors[] = 'application_date';
if (empty($printed_name) || $printed_name !== $full_name) $errors[] = 'printed_name';

if (!empty($errors)) {
    header('Location: ' . $siteUrl . '/hiring.html?error=validation&fields=' . implode(',', $errors));
    exit;
}

// Send email (table mirroring PDF layout)
$mail = new PHPMailer(true);
$emailSent = false;
try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $smtpPort;

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($adminEmail);

    $mail->isHTML(true);
    $mail->Subject = 'New Employment Application: ' . $full_name;
    $mail->Body = '
        <h2>Employment Application - Discount Cuts & Services</h2>
        <table border="1" cellpadding="5" style="width:100%; border-collapse: collapse;">
            <tr><td><strong>Full Name:</strong></td><td>' . htmlspecialchars($full_name) . '</td></tr>
            <tr><td><strong>Email:</strong></td><td>' . htmlspecialchars($email) . '</td></tr>
            <tr><td><strong>Phone:</strong></td><td>' . htmlspecialchars($phone) . '</td></tr>
            <tr><td><strong>Street Address:</strong></td><td>' . nl2br(htmlspecialchars($street_address)) . '</td></tr>
            <tr><td><strong>Years of Relevant Experience:</strong></td><td>' . $years_experience . '</td></tr>
            <tr><td><strong>Desired Pay:</strong></td><td>$' . number_format($desired_pay, 2) . '</td></tr>
            <tr><td><strong>Valid Driver\'s License:</strong></td><td>' . ($drivers_license ? 'Yes [X]' : 'No') . '</td></tr>
            <tr><td><strong>Reliable Transportation:</strong></td><td>' . ($reliable_transport ? 'Yes [X]' : 'No') . '</td></tr>
            <tr><td><strong>Cover Letter:</strong></td><td>' . nl2br(htmlspecialchars($cover_letter)) . '</td></tr>
            <tr><td><strong>Agreement:</strong></td><td>' . ($agreement ? 'Yes [X]' : 'No') . '</td></tr>
            <tr><td><strong>Date:</strong></td><td>' . $application_date . '</td></tr>
            <tr><td><strong>Printed Name (Signature):</strong></td><td>' . htmlspecialchars($printed_name) . '</td></tr>
        </table>
        <p><em>Full PDF attached below if generated.</em></p>
    ';
    $mail->send();
    $emailSent = true;
} catch (Exception $e) {
    if ($debug) error_log("PHPMailer Error: {$mail->ErrorInfo}");
}

// Fill PDF (overlay on your template with Montserrat 12pt)
$pdfGenerated = false;
if (file_exists($pdfTemplate)) {
    require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('Discount Cuts');
    $pdf->SetAuthor($full_name);
    $pdf->SetTitle('Filled Application: ' . $full_name);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    // Register Montserrat font (TTF path—adjust if needed)
    $fontPath = __DIR__ . '/fonts/Montserrat-Regular.ttf';  // Commit this file to /forms/fonts/
    if (file_exists($fontPath)) {
        $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 32);  // Register once
        $pdf->SetFont('montserrat', '', 12);  // Use Montserrat, size 12
    } else {
        $pdf->SetFont('helvetica', '', 12);  // Fallback
        if ($debug) error_log("Montserrat font missing: $fontPath");
    }
    $pdf->SetTextColor(0, 0, 0);

    // Import template page (your PDF)
    $pdf->setSourceFile($pdfTemplate);
    $tplId = $pdf->importPage(1);
    $pdf->useTemplate($tplId, 0, 0, 210);  // Full A4 overlay (210mm width)

    // Overlay fields at template coords (mm from top/left; measured from your PDF)
    // Personal info (top, y=30-60)
    $pdf->SetXY(50, 30); $pdf->Cell(100, 5, $full_name, 0, 1, 'L');  // Full Name
    $pdf->SetXY(50, 40); $pdf->Cell(100, 5, $email, 0, 1, 'L');  // Email
    $pdf->SetXY(50, 50); $pdf->Cell(100, 5, $phone, 0, 1, 'L');  // Phone
    $pdf->SetXY(50, 60); $pdf->MultiCell(100, 5, $street_address, 0, 'L');  // Street Address

    // Green row (mid, y=80-100)
    $pdf->SetXY(50, 80); $pdf->Cell(50, 5, $years_experience, 0, 0, 'L');  // Years Experience
    $pdf->SetXY(120, 80); $pdf->Cell(50, 5, '$' . number_format($desired_pay, 2), 0, 1, 'L');  // Desired Pay
    if ($drivers_license) { $pdf->SetXY(50, 90); $pdf->Cell(10, 5, '[X]', 1, 0, 'C'); }  // License checkbox
    if ($reliable_transport) { $pdf->SetXY(120, 90); $pdf->Cell(10, 5, '[X]', 1, 0, 'C'); }  // Transport checkbox

    // Cover Letter (large box, y=110-140, wrapped)
    $pdf->SetXY(50, 110); $pdf->MultiCell(140, 30, $cover_letter, 1, 'L', false, 0);  // Box height 30mm

    // Agreement/Date (bottom, y=160-170)
    if ($agreement) { $pdf->SetXY(50, 160); $pdf->Cell(10, 5, '[X]', 1, 0, 'C'); }  // Agreement checkbox
    $pdf->SetXY(70, 160); $pdf->Cell(50, 5, $application_date, 0, 1, 'L');  // Date

    // Signature (bottom, y=175-185)
    $pdf->SetXY(50, 175); $pdf->Cell(100, 5, $printed_name, 0, 1, 'L');  // Printed Name (as signature)

    // Output to file
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
    $pdfFile = $outputDir . '/app_' . preg_replace('/[^a-zA-Z0-9]/', '_', $full_name) . '_' . date('YmdHis') . '.pdf';
    $pdf->Output($pdfFile, 'F');
    $pdfGenerated = true;

    // Attach to email if sent
    if ($emailSent) {
        $mail->addAttachment($pdfFile);
        $mail->send();  // Resend with PDF
    }
} else {
    if ($debug) error_log("PDF template missing: $pdfTemplate");
}

// Success redirect (with alert in hiring.html JS)
$redirectUrl = $siteUrl . '/hiring.html?success=1';
if (!$emailSent || !$pdfGenerated) $redirectUrl .= '&error=backend';
header('Location: ' . $redirectUrl);
exit;
?>