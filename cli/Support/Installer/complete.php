<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete | Trongate</title>

    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 55px 20px;
            background: #f5faff;
            color: #18364a;
        }

        .installation {
            width: 100%;
            max-width: 650px;
            margin: 0 auto;
            text-align: center;
        }

        .tg-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 18px;
            display: grid;
            place-items: center;        
        }
        
        .tg-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;        
            display: block;
        }
        
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #2688b8;
            font-size: 13px;
            margin-bottom: 17px;
        }

        .status span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #36b8e9;
        }

        h1 {
            font-size: 29px;
            font-weight: 600;
            margin: 0 0 12px;
            color: #18364a;
        }

        .description {
            max-width: 440px;
            margin: 0 auto 18px;
            color: #718797;
            font-size: 14px;
            line-height: 1.7;
        }

        .edition {
            color: #299bd8;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 23px;
        }

        .setup-button {
            display: inline-block;
            padding: 12px 25px;
            background: #249bd1;
            color: #fff !important;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            transition: .2s ease;
        }

        .setup-button:hover {
            background: #147eaf;
        }

        /* Documentation */

        .documentation {
            margin-top: 55px;
            text-align: left;
        }

        .documentation-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .documentation-heading h2 {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: #29485c;
        }

        .documentation-heading a {
            font-size: 12px;
            color: #299bd8;
            text-decoration: none;
        }

        .docs-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .docs-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            background: #fff;
            border: 1px solid #e4eef5;
            border-radius: 6px;
            text-decoration: none;
            transition: .2s ease;
            min-width: 0;
        }

        .docs-card:hover {
            border-color: #9bd8f3;
            background: #fbfdff;
            transform: translateY(-2px);
        }

        .docs-icon {
            flex-shrink: 0;
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            background: #edf8ff;
            color: #299bd8;
            border-radius: 10px;
            font-size: 16px;
        }

        .docs-content {
            min-width: 0;
        }

        .docs-content strong {
            display: block;
            font-size: 12px;
            color: #29485c;
            margin: 1px 0 5px;
            line-height: 1.4;
        }

        .docs-content small {
            display: block;
            color: #8a9eac;
            font-size: 11px;
            line-height: 1.5;
        }

        .docs-card:last-child {
            grid-column: 1 / -1;
        }

        .footer {
            margin-top: 35px;
            color: #a0b5c2;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            body {
                padding: 40px 16px;
            }

            h1 {
                font-size: 25px;
            }

            .documentation {
                margin-top: 40px;
            }

            .docs-grid {
                gap: 8px;
            }

            .docs-card {
                padding: 11px;
                gap: 8px;
            }

            .docs-icon {
                width: 29px;
                height: 29px;
                font-size: 14px;
            }

            .docs-content strong {
                font-size: 11px;
            }

            .docs-content small {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>

<main class="installation">

    <div class="tg-icon">
        <img src="setup_module/img/t_logo_md.png" alt="TG logo">             
    </div>

    <div class="status">
        <span></span>
        Installation successful
    </div>

    <h1>You're all set.</h1>

    <p class="description">
        Your framework has been successfully installed.
        Let's configure your application and get started.
    </p>

    <div class="edition">
        <?= htmlspecialchars($framework ?? (defined('WEBSITE_NAME') ? WEBSITE_NAME : 'Trongate Framework'), ENT_QUOTES, 'UTF-8') ?>
    </div>

    <a class="setup-button"
        href="<?= BASE_URL?>setup/setup">
        Proceed to Setup &rarr;
    </a>

    <section class="documentation">

        <div class="documentation-heading">
            <h2>Explore the documentation</h2>

            <a href="https://trongateframework.com/documentation"
               target="_blank" rel="noopener noreferrer">
                View all &rarr;
            </a>
        </div>

        <div class="docs-grid">

            <a class="docs-card"
               href="https://trongateframework.com/documentation/trongate_php_framework"
               target="_blank" rel="noopener noreferrer">

                <div class="docs-icon" style="background:#0FA0CE;color:#FFFFFF;">FG</div>

                <div class="docs-content">
                    <strong>Framework Guide</strong>
                    <small>Learn the fundamentals and get started.</small>
                </div>
            </a>

            <a class="docs-card"
               href="https://trongateframework.com/documentation/reference_guide"
               target="_blank" rel="noopener noreferrer">

                <div class="docs-icon" style="background:#7a52fa;color:#FFFFFF;">TR</div>

                <div class="docs-content">
                    <strong>Reference Guide</strong>
                    <small>Classes, methods and helper functions.</small>
                </div>
            </a>

            <a class="docs-card"
               href="https://trongateframework.com/documentation/trongate_css"
               target="_blank" rel="noopener noreferrer">

                <div class="docs-icon" style="background:#08992a;color:#FFFFFF;">CSS</div>

                <div class="docs-content">
                    <strong>Trongate CSS</strong>
                    <small>Build beautiful responsive interfaces.</small>
                </div>
            </a>

            <a class="docs-card"
               href="https://trongateframework.com/documentation/trongate_mx"
               target="_blank" rel="noopener noreferrer">

                <div class="docs-icon" style="background:#e04210;color:#FFFFFF;">MX</div>

                <div class="docs-content">
                    <strong>Trongate MX</strong> 
                    <small>Create dynamic interactive applications.</small>
                </div>
            </a>

            <a class="docs-card"
               href="https://trongateframework.com/documentation/trongate_way"
               target="_blank" rel="noopener noreferrer">

                <div class="docs-icon" style="background:#000000;color:#FFFFFF;">TW</div>

                <div class="docs-content">
                    <strong>The Trongate Way</strong>
                    <small>Practical tutorials and development best practices.</small>
                </div>
            </a>

        </div>

    </section>

    <footer class="footer">
        Native PHP. No bloat. Ready when you are. 
        <div class="load-time">
            Page generated in <?= $load_time ?> ms
        </div>
    </footer>

</main>

</body>
</html>