<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: sans-serif;
            background: #f5f5f5;
            margin: 0;
        }

        .wrap {
            max-width: 520px;
            margin: 40px auto;
        }

        .header {
            background: #1a1a2e;
            padding: 32px 40px;
            text-align: center;
            border-radius: 12px 12px 0 0;
        }

        .header h1 {
            color: #fff;
            font-size: 20px;
            margin: 0;
        }

        .body {
            background: #fff;
            padding: 36px 40px;
        }

        .btn {
            display: block;
            background: #534AB7;
            color: #fff;
            text-align: center;
            padding: 13px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 15px;
            margin: 0 0 28px;
        }

        .footer {
            background: #f9f9f9;
            padding: 20px 40px;
            text-align: center;
            font-size: 12px;
            color: #888;
            border-radius: 0 0 12px 12px;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="header">
            <h1>請驗證您的電子郵件</h1>
        </div>
        <div class="body">
            <p>您好，{{ $user->name }}！</p>
            <p>請點擊下方按鈕完成驗證：</p> <a class="btn" href="{{ $url }}">驗證電子郵件</a>
            <p style="font-size:12px;color:#aaa">連結：{{ $url }}</p>
        </div>
        <div class="footer">
            <p>此連結 60 分鐘後失效</p>
        </div>
    </div>
</body>

</html>