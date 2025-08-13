<?php
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


function sendEmail($to, $subject, $body, $fromEmail = null, $fromName = 'pnevmatpro.ru') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('saport.pnevmatpro@gmail.com') ?: 'saport.pnevmatpro@gmail.com';
        $mail->Password = getenv('zutc wccu qoii leki') ?: 'zutc wccu qoii leki';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $fromEmail = $fromEmail ?: (getenv('ADMIN_EMAIL') ?: 'saport.pnevmatpro@gmail.com');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->CharSet = 'UTF-8';

        $mail->send();
        return true;
    } catch (Exception $e) {
        logToFile("Ошибка отправки письма: {$mail->ErrorInfo}", "ERROR");
        return false;
    }
}
function sendVerificationEmail($user_id, $email, $username) {
    global $pdo, $site_url, $current_year;
    
    $token = bin2hex(random_bytes(32));
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET email_verification_token = ?, email_verification_sent_at = NOW() WHERE id = ?");
        $stmt->execute([$token, $user_id]);
        
        $verification_url = "$site_url/verify_email.php?token=$token&email=" . urlencode($email);
        $admin_email = 'saport.pnevmatpro@gmail.com'; // Default value
        
        $subject = 'Подтверждение email на pnevmatpro.ru';
        $body = "
            <!DOCTYPE html>
            <html lang='ru'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                    .header { text-align: center; padding: 20px; background-color: #2A3950; color: #ffffff; border-radius: 10px 10px 0 0; }
                    .header img { max-width: 150px; }
                    .content { padding: 20px; color: #333333; }
                    .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                    .button:hover { background-color: #0056b3; }
                    .footer { text-align: center; padding: 10px; color: #777777; font-size: 12px; margin-top: 20px; }
                    @media only screen and (max-width: 600px) {
                        .container { width: 100% !important; }
                        .header img { max-width: 100px; }
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <img src='https://pnevmatpro.ru/assets/logo.png' alt='pnevmatpro.ru Logo' style='display: block; margin: 0 auto;'>
                        <h2>pnevmatpro.ru</h2>
                    </div>
                    <div class='content'>
                        <h3>Здравствуйте, $username!</h3>
                        <p>Спасибо за регистрацию на pnevmatpro.ru. Для завершения процесса, пожалуйста, подтвердите ваш email, нажав на кнопку ниже:</p>
                        <a href='$verification_url' class='button'>Подтвердить email</a>
                        <p>Если вы не регистрировались на нашем сайте, пожалуйста, игнорируйте это письмо.</p>
                    </div>
                    <div class='footer'>
                        <p>© $current_year pnevmatpro.ru. Все права защищены.<br>Свяжитесь с нами: <a href='mailto:$admin_email'>$admin_email</a></p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        if (sendEmail($email, $subject, $body, $admin_email)) {
            logActivity($pdo, 'email_verification_sent', "Отправлено письмо подтверждения для $email", null, $user_id);
            return true;
        }
        return false;
    } catch (PDOException $e) {
        logToFile("Ошибка сохранения токена подтверждения: {$e->getMessage()}", "ERROR");
        return false;
    }
}

function sendPasswordResetEmail($email, $token) {
    global $site_url, $current_year;
    
    $reset_url = "$site_url/reset_password.php?token=$token&email=" . urlencode($email);
    $admin_email = 'saport.pnevmatpro@gmail.com'; // Default value
    
    $subject = 'Сброс пароля на pnevmatpro.ru';
    $body = "
        <!DOCTYPE html>
        <html lang='ru'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                .header { text-align: center; padding: 20px; background-color: #2A3950; color: #ffffff; border-radius: 10px 10px 0 0; }
                .header img { max-width: 150px; }
                .content { padding: 20px; color: #333333; }
                .button { display: inline-block; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px; margin-top: 20px; }
                .button:hover { background-color: #0056b3; }
                .footer { text-align: center; padding: 10px; color: #777777; font-size: 12px; margin-top: 20px; }
                @media only screen and (max-width: 600px) {
                    .container { width: 100% !important; }
                    .header img { max-width: 100px; }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <img src='https://pnevmatpro.ru/assets/logo.png' alt='pnevmatpro.ru Logo' style='display: block; margin: 0 auto;'>
                    <h2>pnevmatpro.ru</h2>
                </div>
                <div class='content'>
                    <h3>Сброс пароля</h3>
                    <p>Вы (или кто-то другой) запросили сброс пароля для вашего аккаунта на pnevmatpro.ru.</p>
                    <p>Чтобы сбросить пароль, нажмите на кнопку ниже. Ссылка действительна в течение 1 часа:</p>
                    <a href='$reset_url' class='button'>Сбросить пароль</a>
                    <p>Если вы не запрашивали сброс пароля, пожалуйста, игнорируйте это письмо.</p>
                </div>
                <div class='footer'>
                    <p>© $current_year pnevmatpro.ru. Все права защищены.<br>Свяжитесь с нами: <a href='mailto:$admin_email'>$admin_email</a></p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    if (sendEmail($email, $subject, $body, $admin_email)) {
        logToFile("Письмо для сброса пароля отправлено на $email", "INFO");
        return true;
    }
    return false;
}

function sendAdminPasswordResetEmail($email, $token) {
    global $site_url, $current_year;
    
    $reset_url = "$site_url/admin/admin_reset_password.php?token=$token&email=" . urlencode($email);
    $admin_email = 'saport.pnevmatpro@gmail.com'; // Default value
    
    $subject = 'Сброс пароля административной панели pnevmatpro.ru';
    $body = "
        <!DOCTYPE html>
        <html lang='ru'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: Arial, sans-serif; background-color: #e9ecef; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
                .header { text-align: center; padding: 20px; background-color: #343a40; color: #ffffff; border-radius: 10px 10px 0 0; }
                .header img { max-width: 150px; }
                .content { padding: 20px; color: #212529; }
                .button { display: inline-block; padding: 12px 25px; background-color: #dc3545; color: #ffffff; text-decoration: none; border-radius: 5px; margin-top: 20px; font-weight: bold; }
                .button:hover { background-color: #c82333; }
                .footer { text-align: center; padding: 10px; color: #6c757d; font-size: 12px; margin-top: 20px; border-top: 1px solid #dee2e6; }
                @media only screen and (max-width: 600px) {
                    .container { width: 100% !important; }
                    .header img { max-width: 100px; }
                    .button { padding: 10px 15px; }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <img src='https://pnevmatpro.ru/assets/logo.png' alt='pnevmatpro.ru Admin Logo' style='display: block; margin: 0 auto;'>
                    <h2>Административная панель pnevmatpro.ru</h2>
                </div>
                <div class='content'>
                    <h3>Важное уведомление</h3>
                    <p>Уважаемый администратор, вы (или кто-то другой) инициировали запрос на сброс пароля для доступа к административной панели.</p>
                    <p>Для завершения процесса сброса пароля, пожалуйста, используйте кнопку ниже. Ссылка активна в течение 1 часа:</p>
                    <a href='$reset_url' class='button'>Сбросить пароль администратора</a>
                    <p>Если это не вы запрашивали сброс, немедленно свяжитесь с поддержкой по адресу <a href='mailto:$admin_email'>$admin_email</a>.</p>
                </div>
                <div class='footer'>
                    <p>© $current_year pnevmatpro.ru. Все права защищены.<br>Поддержка: <a href='mailto:$admin_email'>$admin_email</a></p>
                </div>
            </div>
        </body>
        </html>
    ";
    
    if (sendEmail($email, $subject, $body, $admin_email)) {
        logToFile("Письмо для сброса пароля админа отправлено на $email", "INFO");
        return true;
    }
    return false;
}
?>