<?php
declare(strict_types=1);

require_once __DIR__ . '/session-security.php';
session_start();
sessionSecurityEnforce();

require_once __DIR__.'/mapa/banco.php';
require_once __DIR__.'/auth.php';

$pdo = db();
authInit($pdo);

if (authLogged()) {
    if (authRole() === 'vendedor') {
        header('Location: mapa/consulta-vendedores.php');
        exit;
    }

    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a55a6">
<title>Entrar • Liceu Brasil</title>

<style>
:root{
    --liceu-blue:#0b5fbd;
    --liceu-blue-mid:#0a55a6;
    --liceu-blue-dark:#063c7b;
    --liceu-blue-deep:#042d61;
    --liceu-cyan:#67d8ff;
    --white:#ffffff;
    --text:#11335c;
    --muted:#728099;
    --line:#d9e2ef;
    --danger:#d93737;
    --shadow:0 30px 80px rgba(0,34,78,.28);
}

*{
    box-sizing:border-box;
}

html,
body{
    width:100%;
    min-height:100%;
}

body{
    margin:0;
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:28px 18px;
    font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color:var(--text);
    background:
        radial-gradient(circle at 15% 12%, rgba(51,138,226,.28), transparent 25%),
        radial-gradient(circle at 86% 82%, rgba(13,111,207,.22), transparent 28%),
        linear-gradient(135deg, #084892 0%, #0a55a6 48%, #063c7b 100%);
    overflow-x:hidden;
}

body::before,
body::after{
    content:"";
    position:fixed;
    pointer-events:none;
    border:1px solid rgba(255,255,255,.11);
    border-radius:45% 55% 63% 37% / 52% 44% 56% 48%;
}

body::before{
    width:62vw;
    height:62vw;
    left:-26vw;
    top:-30vw;
    transform:rotate(13deg);
}

body::after{
    width:56vw;
    height:56vw;
    right:-25vw;
    bottom:-30vw;
    transform:rotate(-20deg);
}

.login-card{
    position:relative;
    z-index:1;
    width:min(820px, 92vw);
    min-height:465px;
    display:grid;
    grid-template-columns:.92fr 1.08fr;
    overflow:hidden;
    border-radius:22px;
    background:#fff;
    box-shadow:var(--shadow);
}

.brand-side{
    position:relative;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:34px 28px;
    color:#fff;
    background:
        radial-gradient(circle at 48% 40%, rgba(47,135,226,.34), transparent 34%),
        linear-gradient(145deg, var(--liceu-blue) 0%, var(--liceu-blue-mid) 48%, var(--liceu-blue-dark) 100%);
}

.brand-side::before,
.brand-side::after{
    content:"";
    position:absolute;
    width:135%;
    height:165px;
    left:-16%;
    border:1px solid rgba(123,199,255,.18);
    border-radius:50%;
    transform:rotate(-7deg);
}

.brand-side::before{
    bottom:42px;
}

.brand-side::after{
    bottom:-25px;
    opacity:.55;
}

.brand-content{
    position:relative;
    z-index:2;
    width:100%;
    text-align:center;
}

.brand-logo{
    display:block;
    width:min(255px, 78%);
    height:auto;
    margin:0 auto;
    object-fit:contain;
}

.brand-divider{
    width:82px;
    height:2px;
    border-radius:99px;
    margin:24px auto 18px;
    background:linear-gradient(90deg, transparent, var(--liceu-cyan), transparent);
}

.brand-slogan{
    max-width:285px;
    margin:0 auto;
    color:rgba(255,255,255,.95);
    font-size:.86rem;
    line-height:1.55;
    font-weight:500;
}

.brand-slogan strong{
    color:var(--liceu-cyan);
    font-weight:800;
}

.form-side{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:38px 44px;
    background:
        radial-gradient(circle at 100% 0%, #f2f8ff 0%, transparent 32%),
        #fff;
}

.form-wrap{
    width:100%;
    max-width:355px;
}

.welcome{
    text-align:center;
    margin-bottom:25px;
}

.welcome h1{
    margin:0;
    color:#0c4891;
    font-size:1.55rem;
    line-height:1.15;
    letter-spacing:-.035em;
}

.welcome p{
    margin:10px 0 0;
    color:var(--muted);
    font-size:.84rem;
}

.field{
    margin-bottom:15px;
}

.field label{
    display:block;
    margin:0 0 8px 2px;
    color:#123f76;
    font-size:.82rem;
    font-weight:800;
}

.input-wrap{
    position:relative;
}

.input-wrap .leading-icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    width:18px;
    height:18px;
    stroke:#1365be;
    fill:none;
    stroke-width:1.9;
    pointer-events:none;
}

.input-wrap input{
    width:100%;
    height:46px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fcfdff;
    outline:none;
    padding:0 49px 0 47px;
    color:#183a61;
    font-size:.86rem;
    transition:.18s ease;
}

.input-wrap input::placeholder{
    color:#9aa7b8;
}

.input-wrap input:focus{
    border-color:#4a8fd5;
    background:#fff;
    box-shadow:0 0 0 4px rgba(11,95,189,.09);
}

.password-toggle{
    position:absolute;
    top:50%;
    right:10px;
    transform:translateY(-50%);
    width:34px;
    height:34px;
    display:grid;
    place-items:center;
    border:0;
    border-radius:8px;
    background:transparent;
    cursor:pointer;
}

.password-toggle svg{
    width:18px;
    height:18px;
    stroke:#7e8ba0;
    fill:none;
    stroke-width:1.9;
}

.login-button{
    width:100%;
    height:46px;
    margin-top:3px;
    border:0;
    border-radius:12px;
    background:linear-gradient(90deg, #0a58ad, #0b65c8);
    color:#fff;
    font-size:.9rem;
    font-weight:900;
    cursor:pointer;
    box-shadow:0 12px 26px rgba(10,88,173,.24);
    transition:.18s ease;
}

.login-button:hover{
    transform:translateY(-1px);
    box-shadow:0 15px 30px rgba(10,88,173,.3);
}

.login-button:disabled{
    opacity:.62;
    cursor:wait;
    transform:none;
}

.message{
    min-height:22px;
    margin-top:11px;
    text-align:center;
    color:var(--danger);
    font-size:.8rem;
}

.security{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    margin-top:16px;
    color:#8b9aad;
    font-size:.72rem;
}

.security svg{
    width:14px;
    height:14px;
    fill:none;
    stroke:#768da7;
    stroke-width:2;
}

.footer{
    margin-top:20px;
    text-align:center;
    color:#9aa6b4;
    font-size:.69rem;
}

.footer strong{
    color:#61758d;
}

@media(max-width:820px){
    body{
        padding:16px;
    }

    .login-card{
        width:min(560px, 96vw);
        grid-template-columns:1fr;
        min-height:auto;
    }

    .brand-side{
        min-height:235px;
        padding:31px 24px;
    }

    .brand-logo{
        width:min(290px, 74%);
    }

    .brand-divider{
        margin:24px auto 17px;
    }

    .brand-slogan{
        font-size:.98rem;
    }

    .form-side{
        padding:40px 30px 34px;
    }
}

@media(max-width:480px){
    .login-card{
        border-radius:20px;
    }

    .brand-side{
        min-height:190px;
        padding:25px 20px;
    }

    .brand-logo{
        width:min(250px, 78%);
    }

    .brand-divider{
        margin:18px auto 13px;
    }

    .brand-slogan{
        max-width:280px;
        font-size:.88rem;
    }

    .form-side{
        padding:31px 22px 27px;
    }

    .welcome{
        margin-bottom:27px;
    }

    .welcome h1{
        font-size:1.58rem;
    }
}

@media (min-width:821px) and (max-height:800px){
    body{padding:14px 18px}
    .login-card{width:min(790px,92vw);min-height:430px}
    .brand-side{padding:28px 24px}
    .brand-logo{width:min(235px,76%)}
    .brand-divider{margin:20px auto 15px}
    .form-side{padding:30px 40px}
    .welcome{margin-bottom:20px}
    .welcome h1{font-size:1.45rem}
    .field{margin-bottom:12px}
    .input-wrap input,.login-button{height:44px}
    .security{margin-top:13px}
    .footer{margin-top:15px}
}
</style>
</head>

<body>

<main class="login-card">
    <section class="brand-side">
        <div class="brand-content">
            <img
                class="brand-logo"
                src="logo-liceu.png"
                alt="Liceu Brasil"
            >

            <div class="brand-divider"></div>

            <p class="brand-slogan">
                Transformando vidas através da <strong>educação.</strong>
            </p>
        </div>
    </section>

    <section class="form-side">
        <div class="form-wrap">
            <header class="welcome">
                <h1>Bem-vindo de volta!</h1>
                <p>Acesse sua conta para continuar.</p>
            </header>

            <form id="loginForm" novalidate>
                <div class="field">
                    <label for="username">Usuário</label>

                    <div class="input-wrap">
                        <svg class="leading-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20 21a8 8 0 0 0-16 0"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>

                        <input
                            id="username"
                            type="text"
                            autocomplete="username"
                            placeholder="Digite seu usuário"
                            autofocus
                            required
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Senha</label>

                    <div class="input-wrap">
                        <svg class="leading-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="5" y="10" width="14" height="10" rx="2"/>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        </svg>

                        <input
                            id="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Digite sua senha"
                            required
                        >

                        <button
                            class="password-toggle"
                            id="togglePassword"
                            type="button"
                            aria-label="Mostrar senha"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="2.5"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="login-button" id="submitButton" type="submit">
                    Entrar
                </button>

                <div class="message" id="message"></div>
            </form>

            <div class="security">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6l-7-3Z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                Acesso seguro e restrito a usuários autorizados
            </div>

            <div class="footer">
                <strong>Liceu Brasil</strong> • Sistema Integrado de Gestão
            </div>
        </div>
    </section>
</main>

<script>
const loginForm = document.getElementById('loginForm');
const username = document.getElementById('username');
const password = document.getElementById('password');
const submitButton = document.getElementById('submitButton');
const message = document.getElementById('message');
const togglePassword = document.getElementById('togglePassword');

togglePassword.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    togglePassword.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
});

loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    message.textContent = '';

    if (!username.value.trim() || !password.value) {
        message.textContent = 'Informe usuário e senha.';
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Entrando...';

    try {
        const response = await fetch('auth-api.php?action=login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                username: username.value.trim(),
                password: password.value
            })
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || 'Não foi possível entrar.');
        }

        location.replace(data.user.role === 'vendedor'
            ? 'mapa/consulta-vendedores.php'
            : 'index.php');

    } catch (error) {
        message.textContent = error.message;
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Entrar';
    }
});
</script>

</body>
</html>
