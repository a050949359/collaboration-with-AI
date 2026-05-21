<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: 'Space Grotesk', 'Segoe UI', Arial, sans-serif;
            background: #0f1511;
            margin: 0;
            color: #dee4dd;
        }
        .wrap {
            max-width: 480px;
            margin: 48px auto;
            background: rgba(15,21,17,0.92);
            border-radius: 2rem;
            box-shadow: 0 8px 32px 0 #6bdc9f22;
            backdrop-filter: blur(20px);
            overflow: hidden;
        }
        .header {
            padding: 36px 44px 0 44px;
            text-align: left;
        }
        .header h1 {
            color: #6bdc9f;
            font-size: 2rem;
            font-weight: bold;
            margin: 0 0 8px 0;
            letter-spacing: -1px;
        }
        .body {
            padding: 32px 44px 0 44px;
        }
        .body p {
            color: #dee4dd;
            font-size: 1.05rem;
            line-height: 1.7;
            margin: 0 0 18px 0;
        }
        .btn {
            display: block;
            width: 100%;
            background: linear-gradient(145deg,#6bdc9f,#2ca46d);
            color: #0f1511;
            text-align: center;
            padding: 15px 0;
            border-radius: 0.75rem;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0 0 18px 0;
            box-shadow: 0 2px 8px 0 #6bdc9f22;
            letter-spacing: 0.5px;
        }
        .btn:hover {
            filter: brightness(1.08);
        }
        .link {
            display: block;
            word-break: break-all;
            font-size: 0.92rem;
            color: #a5d1b4;
            margin-bottom: 0;
            text-decoration: underline dotted;
            max-width: 100%;
        }
        .footer {
            padding: 28px 44px 28px 44px;
            text-align: right;
            font-size: 0.92rem;
            color: #a5d1b4;
            background: transparent;
        }
        @media (max-width: 600px) {
            .wrap, .header, .body, .footer {
                padding-left: 18px !important;
                padding-right: 18px !important;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="header">
            <h1>重設您的密碼</h1>
        </div>
        <div class="body">
            <p>您好，{{ $user->name }}！</p>
            <p>請點擊下方按鈕重設您的密碼：</p>
            <a class="btn" href="{{ $url }}">重設密碼</a>
            <span class="link">{{ $url }}</span>
        </div>
        <div class="footer">
            此連結 60 分鐘後失效，若非本人操作請忽略此信。
        </div>
    </div>
</body>

</html>
