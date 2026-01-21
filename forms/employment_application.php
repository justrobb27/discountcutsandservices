<?php
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/../vendor/setasign/fpdi/src/Fpdi.php';  // Manual FPDI load

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
// Sanitize new fields
$street_address = trim(filter_var($_POST['street_address'] ?? '', FILTER_SANITIZE_STRING));
$city = trim(filter_var($_POST['city'] ?? '', FILTER_SANITIZE_STRING));
$state = trim(filter_var($_POST['state'] ?? '', FILTER_SANITIZE_STRING));
$zip = trim(filter_var($_POST['zip'] ?? '', FILTER_SANITIZE_STRING));
$apt_suite = trim(filter_var($_POST['apt_suite'] ?? '', FILTER_SANITIZE_STRING));

// Full address for email
$full_address = !empty($apt_suite) ? $street_address . ', ' . $apt_suite : $street_address;
$full_address .= !empty($city) || !empty($state) || !empty($zip) ? ', ' . implode(', ', array_filter([$city, $state, $zip])) : '';

// Combined for template (2 lines: street/apt, city/state/zip)
$street_line = $street_address . (!empty($apt_suite) ? ', ' . $apt_suite : '');
$city_line = $city . (!empty($city) ? ', ' : '') . $state . (!empty($zip) ? ' ' . $zip : '');

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
if (empty($phone) || !preg_match('/^\d{3}-\d{3}-\d{4}$/', $phone)) $errors[] = 'phone';
if (empty($street_address)) $errors[] = 'street_address';
if (empty($city)) $errors[] = 'city';
if (empty($state)) $errors[] = 'state';
if (empty($zip) || !preg_match('/^\d{5}(-\d{4})?$/', $zip)) $errors[] = 'zip';
if (!empty($apt_suite) && strlen($apt_suite) > 100) $errors[] = 'apt_suite';
if ($years_experience < 0) $errors[] = 'years_experience';
if ($desired_pay < 0) $errors[] = 'desired_pay';
if (strlen($cover_letter) < 20) $errors[] = 'cover_letter';
if (!$agreement) $errors[] = 'agreement';
if (empty($application_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $application_date)) $errors[] = 'application_date';
if (empty($printed_name) || $printed_name !== $full_name) $errors[] = 'printed_name';

if (!empty($errors)) {
    header('Location: ' . $siteUrl . '/hiring.html?error=validation&fields=' . implode(',', $errors));
    exit;
}

// Fill PDF (overlay on your template with Montserrat 12pt) - Generate BEFORE email
$pdfGenerated = false;
$pdfFile = null;
$pdfAttached = false;
if (file_exists($pdfTemplate)) {
    $pdf = new Fpdi();  // FPDI extends TCPDF for import
    $pdf->SetCreator('Discount Cuts');
    $pdf->SetAuthor($full_name);
    $pdf->SetTitle('Filled Application: ' . $full_name);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    // Log version for debug
    if ($debug) error_log("TCPDF/FPDI Version: " . $pdf->getTcpdfVersion());

    // Register Montserrat font (safe with fallback)
    $fontPath = __DIR__ . '/fonts/Montserrat-Regular.ttf';
    if (file_exists($fontPath)) {
        // Try addTTFFont (6.x+)
        if (method_exists($pdf, 'addTTFFont')) {
            try {
                $pdf->addTTFFont($fontPath, 'TrueTypeUnicode', '', 32);
                $pdf->SetFont('montserrat', '', 12);
                if ($debug) error_log("Custom font registered (TTFFont)");
            } catch (Exception $e) {
                if ($debug) error_log("addTTFFont failed: " . $e->getMessage());
                $pdf->SetFont('helvetica', '', 12);
            }
        } 
        // Try addTTFfont (5.x)
        elseif (method_exists($pdf, 'addTTFfont')) {
            try {
                $pdf->addTTFfont($fontPath, 'TrueTypeUnicode', '', 32);
                $pdf->SetFont('montserrat', '', 12);
                if ($debug) error_log("Custom font registered (TTFfont)");
            } catch (Exception $e) {
                if ($debug) error_log("addTTFfont failed: " . $e->getMessage());
                $pdf->SetFont('helvetica', '', 12);
            }
        } else {
            $pdf->SetFont('helvetica', '', 12);
            if ($debug) error_log("No font method—using Helvetica");
        }
    } else {
        $pdf->SetFont('helvetica', '', 12);
        if ($debug) error_log("Montserrat missing—using Helvetica");
    }
    $pdf->SetTextColor(0, 0, 0);

    // Import template page (FPDI handles this)
    try {
        $tplId = $pdf->setSourceFile($pdfTemplate);
        $pageId = $pdf->importPage(1);
        $pdf->useTemplate($pageId, 0, 0, 210);  // Full A4 overlay
        if ($debug) error_log("Template imported: PageID $pageId");
    } catch (Exception $e) {
        if ($debug) error_log("Template import error: " . $e->getMessage());
        $pdf->AddPage();  // Plain fallback
    }

    // Overlay fields at template coords (refined for your screenshot—street line y=55, city y=65)
    // Personal info (top, y=25-45)
    $pdf->SetXY(45, 25); $pdf->Cell(105, 6, $full_name, 0, 1, 'L');  // Full Name
    $pdf->SetXY(45, 35); $pdf->Cell(105, 6, $email, 0, 1, 'L');  // Email
    $pdf->SetXY(45, 45); $pdf->Cell(105, 6, $phone, 0, 1, 'L');  // Phone

    // Address lines (street/apt combined y=55, city/state/zip combined y=65)
    $pdf->SetXY(45, 55); $pdf->Cell(105, 6, $street_line, 0, 1, 'L');  // Street + Apt (no spill)
    $pdf->SetXY(45, 65); $pdf->Cell(105, 6, $city_line, 0, 1, 'L');  // City, State ZIP combined

    // Green row (y=75-85)
    $pdf->SetXY(45, 75); $pdf->Cell(45, 6, $years_experience, 0, 0, 'L');  // Years
    $pdf->SetXY(95, 75); $pdf->Cell(65, 6, '$' . number_format($desired_pay, 2), 0, 1, 'L');  // Pay
    if ($drivers_license) { $pdf->SetXY(45, 85); $pdf->Cell(12, 8, '[X]', 1, 0, 'C'); }  // License [X]
    if ($reliable_transport) { $pdf->SetXY(100, 85); $pdf->Cell(12, 8, '[X]', 1, 0, 'C'); }  // Transport [X]

    // Cover Letter (y=95-140, taller for wrap)
    $pdf->SetXY(45, 95); $pdf->MultiCell(140, 45, $cover_letter, 1, 'L', false, 0);  // Height 45mm

    // Agreement/Date (y=145-155)
    if ($agreement) { $pdf->SetXY(45, 145); $pdf->Cell(12, 8, '[X]', 1, 0, 'C'); }  // Agreement [X]
    $pdf->SetXY(65, 145); $pdf->Cell(50, 6, $application_date, 0, 1, 'L');  // Date

    // Signature (y=155)
    $pdf->SetXY(45, 155); $pdf->Cell(105, 6, $printed_name, 0, 1, 'L');  // Printed Name

    // Output to file
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
    $pdfFile = $outputDir . '/app_' . preg_replace('/[^a-zA-Z0-9]/', '_', $full_name) . '_' . date('YmdHis') . '.pdf';
    $pdf->Output($pdfFile, 'F');
    $pdfGenerated = true;

    if ($debug) error_log("PDF generated: $pdfFile");
} else {
    if ($debug) error_log("PDF template missing: $pdfTemplate");
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

    // Attach PDF if generated
    if ($pdfGenerated && file_exists($pdfFile)) {
        $mail->addAttachment($pdfFile);
        $pdfAttached = true;
    }

    $mail->Body = '
        <h2>Employment Application - Discount Cuts & Services</h2>
        <table border="1" cellpadding="5" style="width:100%; border-collapse: collapse;">
            <tr><td><strong>Full Name:</strong></td><td>' . htmlspecialchars($full_name) . '</td></tr>
            <tr><td><strong>Email:</strong></td><td>' . htmlspecialchars($email) . '</td></tr>
            <tr><td><strong>Phone:</strong></td><td>' . htmlspecialchars($phone) . '</td></tr>
            <tr><td><strong>Street Address:</strong></td><td>' . nl2br(htmlspecialchars($street_address)) . '</td></tr>
            <tr><td><strong>Apt/Suite:</strong></td><td>' . htmlspecialchars($apt_suite) . '</td></tr>
            <tr><td><strong>City:</strong></td><td>' . htmlspecialchars($city) . '</td></tr>
            <tr><td><strong>State:</strong></td><td>' . htmlspecialchars($state) . '</td></tr>
            <tr><td><strong>ZIP Code:</strong></td><td>' . htmlspecialchars($zip) . '</td></tr>
            <tr><td><strong>Full Address:</strong></td><td>' . nl2br(htmlspecialchars($full_address)) . '</td></tr>
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

// Clean up PDF file if attached (for security)
if ($pdfAttached && file_exists($pdfFile)) {
    unlink($pdfFile);
}

// Success redirect (with alert in hiring.html JS)
$redirectUrl = $siteUrl . '/hiring.html?success=1';
if (!$emailSent || !$pdfGenerated) $redirectUrl .= '&error=backend';
header('Location: ' . $redirectUrl);
exit;
?>