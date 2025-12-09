<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credenziali di Accesso</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #F4F4F4; padding: 20px;">

<table align="center" width="600" style="background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.05);">
    <!-- Header -->
    <tr>
        <td style="background-color: #212B36; padding: 20px; text-align: center;">
            <img src="https://auditors.gcerti.it/images/brand/logo/brand-logo-white.png" alt="Logo Azienda" style="max-width: 150px;">
        </td>
    </tr>

    <!-- Titolo -->
    <tr>
        <td style="padding: 20px; text-align: center; background-color: #A11925;">
            <h2 style="color: #FFFFFF; margin: 0;">Benvenuto in Gcerti Italy</h2>
        </td>
    </tr>

    <!-- Contenuto -->
    <tr>
        <td style="padding: 20px; color: #212B36; font-size: 15px; line-height: 1.6;">
            <p>Ciao <strong>{{ $auditor->name }} {{ $auditor->surname }}</strong>,</p>
            <p>il tuo account è stato creato con successo.</p>

            <p>Ecco le tue credenziali di accesso:</p>
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li><strong>Email:</strong> {{ $email }}</li>
                <li><strong>Password:</strong> {{ $password }}</li>
            </ul>

            <p style="margin-top: 20px;">
                Ti invitiamo ad accedere immediatamente al portale e <strong>cambiare la password</strong> al primo login.
            </p>
        </td>
    </tr>

    <!-- Footer -->
    <tr>
        <td style="background-color: #F4F4F4; padding: 15px; text-align: center; font-size: 12px; color: #666;">
            © {{ date('Y') }} Gcerti Italy. Tutti i diritti riservati.
        </td>
    </tr>
</table>

</body>
</html>
