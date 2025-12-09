<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Auditor Inserito</title>
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
            <h2 style="color: #FFFFFF; margin: 0;">Nuovo Auditor Inserito</h2>
        </td>
    </tr>

    <!-- Contenuto -->
    <tr>
        <td style="padding: 20px; color: #212B36; font-size: 15px; line-height: 1.6;">
            <p>È stato appena inserito un nuovo auditor nel sistema.</p>
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li><strong>ID:</strong> {{ $auditor->id }}</li>
                <li><strong>Nome:</strong> {{ $auditor->name }}</li>
                <li><strong>Cognome:</strong> {{ $auditor->surname }}</li>
            </ul>
            <p style="margin-top: 20px;">
                Ricorda di aggiungere le <strong>tariffe associate</strong>, <strong>creare il contratto</strong> e generare <strong>i dati di accesso per l'utente</strong>.
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
