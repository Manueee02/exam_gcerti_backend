<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #F4F4F4; padding: 20px;">

<table align="center" width="600" style="background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.05);">
    <!-- Header con logo -->
    <tr>
        <td style="background-color: #212B36; padding: 20px; text-align: center;">
            <img src="https://auditors.gcerti.it/images/brand/logo/brand-logo-white.png" alt="Logo Azienda" style="max-width: 150px;">
        </td>
    </tr>

    <!-- Titolo -->
    <tr>
        <td style="padding: 20px; text-align: center; background-color: #A11925;">
            <h2 style="color: #FFFFFF; margin: 0;">Reset Password</h2>
        </td>
    </tr>

    <!-- Contenuto -->
    <tr>
        <td style="padding: 30px; color: #212B36; font-size: 15px; line-height: 1.6;">
            <p>Ciao <strong>{{ $user->name }}</strong>,</p>

            <p>Hai ricevuto questa email perché abbiamo ricevuto una richiesta di reset della password per il tuo account.</p>

            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $resetUrl }}"
                   style="display: inline-block;
                          padding: 10px 20px;
                          background-color: #A11925;
                          color: white;
                          text-decoration: none;
                          border-radius: 5px;
                          font-weight: bold;">
                    Reimposta Password
                </a>
            </div>

            <p><strong>Importante:</strong> Questo link è valido per <strong>1 ora</strong>. Se non hai richiesto il reset della password, ignora questa email.</p>

            <div style="background-color: #F8F9FA;
                        padding: 20px;
                        border-radius: 5px;
                        margin: 20px 0;
                        border-left: 4px solid #A11925;">
                <h4 style="margin-top: 0; color: #A11925;">Se il pulsante non funziona:</h4>
                <p style="margin-bottom: 0; font-size: 14px;">
                    Copia e incolla questo link nel tuo browser:
                    <br><span style="color: #666; word-break: break-all;">{{ $resetUrl }}</span>
                </p>
            </div>

            <p style="margin-top: 30px; font-size: 14px; color: #666;">
                Se hai difficoltà, contatta il supporto tecnico.
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

