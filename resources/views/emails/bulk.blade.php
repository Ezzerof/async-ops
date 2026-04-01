<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f4; font-family: Arial, Helvetica, sans-serif;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4; padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background-color:#ffffff; border-radius:6px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:#1a1a2e; padding:24px 32px;">
                            <p style="margin:0; font-size:20px; font-weight:bold; color:#ffffff; letter-spacing:0.5px;">Async Ops</p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px; color:#333333; font-size:15px; line-height:1.7;">
                            {!! $body !!}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color:#f9f9f9; border-top:1px solid #eeeeee; padding:20px 32px; text-align:center;">
                            <p style="margin:0; font-size:12px; color:#999999;">You received this email because you were included in a bulk send.</p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
