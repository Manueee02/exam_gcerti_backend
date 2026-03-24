<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; background-color: #F4F4F4; padding: 20px;">

<table align="center" width="600" style="background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.05);">
    <!-- Header con logo -->
    <tr>
        <td style="background-color: #F8F9FA; padding: 20px; text-align: center;">
            <img src="https://auditors.gcerti.it/images/brand/logo/brand-logo.png" alt="Logo Azienda" style="max-width: 150px;">
        </td>
    </tr>

    <!-- Titolo -->
    <tr>
        <td style="padding: 20px; background-color: #3275E0;">
            <h2 style="color: #FFFFFF; margin: 0; text-align: center;">
                C'è un aggiornamento per la tua iscrizione
            </h2>
        </td>
    </tr>

    <!-- Contenuto -->
    <tr>
        <td style="padding: 20px; color: #212B36; font-size: 15px; line-height: 1.6;">
            <p>Ciao,  <strong>{{ $candidate->name }}</strong>
            </p>

            <p>Speriamo di trovarti bene, qui di seguito trovi un aggiornamento riguardo alla tua iscrizione:</p>

            <p>
                <strong>{{ $statusMessage }}</strong>
            </p>

            <p>
                Accedi alla piattaforma per verificare la richiesta.
            </p>

            <table align="center" cellpadding="0" cellspacing="0" style="margin-top: 20px;">
                <tr>
                    <td align="center">
                        <a href="{{ $link }}"
                           style="display: inline-block; padding: 10px 16px; background-color: #3275E0; color: #FFFFFF; text-decoration: none; border-radius: 4px;">
                            Visualizza richiesta
                        </a>
                    </td>
                </tr>
            </table>
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
