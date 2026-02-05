<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Email</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #F4F4F4; padding: 20px;">

<table align="center" width="600" style="background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.05);">
    <!-- Header -->
    <tr>
        <td style="background-color: #F8F9FA; padding: 20px; text-align: center;">
            <img src="https://auditors.gcerti.it/images/brand/logo/brand-logo.png" alt="Logo Azienda" style="max-width: 150px;">
        </td>
    </tr>

    <!-- Titolo -->
    <tr>
        <td style="padding: 20px; text-align: center; background-color: #0A656C;">
            <h2 style="color: #FFFFFF; margin: 0;">Benvenuto in Gcerti Italy</h2>
        </td>
    </tr>

    <!-- Contenuto -->
    <tr>
        <td style="padding: 20px; color: #212B36; font-size: 15px; line-height: 1.6;">
            <p>Ciao <strong>{{ $user->name }}</strong>,</p>
            <p>Grazie per esserti registrato! Per completare la registrazione, clicca sul pulsante qui sotto per verificare la tua email:</p>

            <div style="text-align: center; margin-top: 30px">
                <a
                    href="{{ $verificationLink }}"
                    style="
                        display: inline-block;
                        background-color: #0A656C;
                        color: #ffffff;
                        text-decoration: none;
                        padding: 14px 32px;
                        border-radius: 6px;
                        font-weight: bold;
                        font-size: 16px;
                    "
                >
                    Verifica Email
                </a>
            </div>

            <p style="margin-top: 20px;">
                Se non hai richiesto questa registrazione, ignora questa email.
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
