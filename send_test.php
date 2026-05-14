<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'src/PHPMailer.php';
require 'src/SMTP.php';
require 'src/Exception.php';

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'secuyamonica8@gmail.com';   // your Gmail
    $mail->Password   = 'bjnh mheb wexs dqfd';          // <-- your new App Password
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('secuyamonica8@gmail.com', 'Admin Test');
    $mail->addAddress('secuyamonica8@gmail.com'); // send to yourself

    $mail->isHTML(true);
    $mail->Subject = 'Test Email';
    $mail->Body    = '<p>This is a test email from PHPMailer ✅</p>';

    $mail->send();
    echo "✅ Email sent successfully!";
} catch (Exception $e) {
    echo "❌ Mailer Error: {$mail->ErrorInfo}";
}
