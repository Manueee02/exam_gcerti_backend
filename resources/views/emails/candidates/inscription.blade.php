<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $mailSubject }}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #F4F4F4; padding: 20px; margin: 0;">

<table align="center" width="600" cellpadding="0" cellspacing="0"
       style="background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 8px rgba(0,0,0,0.05);">

    {{-- Logo --}}
    <tr>
        <td style="background-color: #F8F9FA; padding: 20px; text-align: center;">
            <img src="https://auditors.gcerti.it/images/brand/logo/brand-logo.png"
                 alt="Gcerti Italy"
                 style="max-width: 150px;">
        </td>
    </tr>

    {{-- Titolo --}}
    <tr>
        <td style="padding: 20px; background-color: #3275E0;">
            <h2 style="color: #FFFFFF; margin: 0; text-align: center; font-size: 18px;">
                {{ $mailTitle }}
            </h2>
        </td>
    </tr>

    {{-- Corpo --}}
    <tr>
        <td style="padding: 28px 24px; color: #212B36; font-size: 15px; line-height: 1.7;">

            <p style="margin: 0 0 12px;">
                Ciao, <strong>{{ $candidate->name }}</strong>
            </p>

            <p style="margin: 0 0 20px;">
                {{ $mailBody }}
            </p>

            <table align="center" cellpadding="0" cellspacing="0" style="margin-top: 8px;">
                <tr>
                    <td align="center">
                        <a href="{{ $link }}"
                           style="display: inline-block; padding: 10px 20px; background-color: #3275E0;
                                  color: #FFFFFF; text-decoration: none; border-radius: 4px;
                                  font-size: 14px; font-weight: bold;">
                            Visualizza richiesta
                        </a>
                    </td>
                </tr>
            </table>

        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="background-color: #F4F4F4; padding: 15px; text-align: center;
                   font-size: 12px; color: #888888; border-top: 1px solid #E0E0E0;">
            © {{ date('Y') }} Gcerti Italy. Tutti i diritti riservati.
        </td>
    </tr>

</table>

</body>
</html>
