<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Como foi seu atendimento?</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; font-family: Arial, Helvetica, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8; padding:20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                    style="background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.05);">

                    {{-- Header --}}
                    <tr>
                        <td style="background:#111827; padding:24px; text-align:center;">
                            <h1 style="color:#ffffff; margin:0; font-size:22px;">
                                💈 {{ $companyName }}
                            </h1>
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding:30px; color:#374151;">
                            <p style="font-size:16px; margin-top:0;">
                                Olá <strong>{{ $clientName }}</strong> 😊
                            </p>

                            <p style="font-size:15px; line-height:1.6;">
                                Esperamos que você tenha curtido o atendimento de
                                <strong>{{ $serviceName }}</strong> na
                                <strong>{{ $companyName }}</strong>.
                            </p>

                            <p style="font-size:15px; line-height:1.6;">
                                Sua opinião é muito importante pra gente e leva menos de
                                <strong>1 minuto</strong> para responder.
                            </p>

                            {{-- Button --}}
                            <div style="text-align:center; margin:32px 0;">
                                <a href="{{ $feedbackLink }}"
                                   style="
                                    background:#2563eb;
                                    color:#ffffff;
                                    text-decoration:none;
                                    padding:14px 26px;
                                    border-radius:6px;
                                    font-size:16px;
                                    display:inline-block;
                                   ">
                                    ⭐ Avaliar atendimento
                                </a>
                            </div>

                            <p style="font-size:14px; color:#6b7280; line-height:1.5;">
                                Seu feedback ajuda a melhorar cada detalhe para as próximas visitas.
                            </p>

                            <p style="font-size:14px; color:#6b7280;">
                                Obrigado por escolher a gente 💙
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background:#f9fafb; padding:16px; text-align:center; font-size:12px; color:#9ca3af;">
                            Este convite foi enviado automaticamente após o seu atendimento.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
