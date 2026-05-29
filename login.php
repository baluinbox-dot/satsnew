<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>STAS — Smart Trailing Accumulation System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:#f0f2f5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}

  /* ── TWO-COLUMN WRAPPER ── */
  .split-wrap{display:flex;width:100%;max-width:960px;min-height:560px;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.13);}

  /* ── LEFT — ABOUT PANEL ── */
  .split-left{flex:1;background:linear-gradient(145deg,#185FA5 0%,#0c3d75 100%);color:#fff;padding:48px 40px;display:flex;flex-direction:column;justify-content:space-between;}
  .left-logo{display:flex;align-items:center;gap:12px;margin-bottom:32px;}
  .left-icon{width:44px;height:44px;background:rgba(255,255,255,0.18);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;}
  .left-brand{font-size:22px;font-weight:700;letter-spacing:.5px;}
  .left-tagline{font-size:13px;opacity:.75;}
  .left-headline{font-size:22px;font-weight:700;line-height:1.4;margin-bottom:10px;}
  .left-sub{font-size:13px;opacity:.8;line-height:1.7;margin-bottom:28px;}
  .feat-list{display:flex;flex-direction:column;gap:14px;margin-bottom:32px;}
  .feat-item{display:flex;align-items:flex-start;gap:10px;}
  .feat-icon{font-size:18px;flex-shrink:0;margin-top:1px;}
  .feat-text strong{display:block;font-size:13px;font-weight:600;}
  .feat-text span{font-size:12px;opacity:.75;}
  .left-bottom{}
  .plan-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:600;margin-bottom:10px;}
  .left-contact{font-size:12px;opacity:.65;}
  .left-contact a{color:#fff;text-decoration:none;opacity:.9;}
  .left-contact a:hover{text-decoration:underline;}
  .btn-wa-sm{display:inline-flex;align-items:center;gap:5px;background:#25D366;color:#fff!important;border:none;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;margin-top:8px;}
  .btn-wa-sm:hover{background:#1ebe5d;color:#fff;}

  /* ── RIGHT — LOGIN PANEL ── */
  .split-right{width:380px;flex-shrink:0;background:#fff;padding:48px 40px;display:flex;flex-direction:column;justify-content:center;}
  .right-title{font-size:20px;font-weight:700;color:#1a1a2e;margin-bottom:4px;}
  .right-sub{font-size:13px;color:#6c757d;margin-bottom:24px;}
  .tab-btns{display:flex;border-radius:8px;background:#f0f2f5;padding:3px;margin-bottom:22px;}
  .tab-btn{flex:1;text-align:center;padding:8px;font-size:13px;border-radius:6px;cursor:pointer;border:none;background:transparent;color:#6c757d;font-weight:500;transition:all .15s;}
  .tab-btn.active{background:#fff;color:#185FA5;box-shadow:0 1px 4px rgba(0,0,0,.1);}
  .form-label{font-size:13px;font-weight:500;color:#444;}
  .form-control{font-size:14px;border-radius:8px;border:1px solid #d0d0d0;padding:10px 13px;}
  .form-control:focus{border-color:#185FA5;box-shadow:0 0 0 3px rgba(24,95,165,.12);}
  .btn-login{background:#185FA5;border:none;color:#fff;width:100%;padding:11px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;transition:background .2s;margin-top:4px;}
  .btn-login:hover{background:#0c447c;}
  .btn-login:disabled{background:#7baad0;cursor:not-allowed;}
  #loginError,#registerError{display:none;}
  .footer-copy{text-align:center;font-size:11px;color:#aaa;margin-top:20px;}
  .spinner-border-sm{width:14px;height:14px;}
  /* CAPTCHA */
  .captcha-wrap{background:#f0f5ff;border:1.5px dashed #185FA5;border-radius:8px;padding:9px 12px;display:flex;align-items:center;gap:8px;margin-bottom:12px;}
  .captcha-q{font-size:16px;font-weight:700;color:#185FA5;font-family:monospace;min-width:80px;}
  .captcha-in{flex:1;min-width:0;}
  .captcha-btn{background:none;border:none;font-size:16px;cursor:pointer;opacity:.6;padding:2px;}
  .captcha-btn:hover{opacity:1;}
  .free-note{text-align:center;font-size:11px;color:#888;margin-top:6px;}

  /* ── MOBILE: stack vertically ── */
  @media(max-width:720px){
    body{padding:0;align-items:flex-start;}
    .split-wrap{flex-direction:column;max-width:100%;border-radius:0;box-shadow:none;min-height:100vh;}
    .split-left{padding:32px 24px;}
    .feat-list{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .split-right{width:100%;padding:32px 24px;}
  }
  @media(max-width:420px){
    .feat-list{grid-template-columns:1fr;}
  }
</style>
</head>
<body>

<div class="split-wrap">

  <!-- ══ LEFT — ABOUT ══════════════════════════════════ -->
  <div class="split-left">

    <div>
      <div class="left-logo">
        <div class="left-icon">📈</div>
        <div>
          <div class="left-brand">STAS</div>
          <div class="left-tagline">by FinOps Digital Solutions</div>
        </div>
      </div>

      <div class="left-headline">Smart Trailing<br>Accumulation System</div>
      <div class="left-sub">A rules-based NSE stock tracker that tells you exactly <strong>when to buy more</strong> and <strong>when to book profit</strong> — without emotion.</div>

      <div class="feat-list">
        <div class="feat-item">
          <span class="feat-icon">🎯</span>
          <div class="feat-text">
            <strong>M1 Trail System</strong>
            <span>10% rise → activates trail. 5% pullback → BUY signal.</span>
          </div>
        </div>
        <div class="feat-item">
          <span class="feat-icon">📊</span>
          <div class="feat-text">
            <strong>Live NSE Prices</strong>
            <span>Real-time CMP. Portfolio P&amp;L always live.</span>
          </div>
        </div>
        <div class="feat-item">
          <span class="feat-icon">🔔</span>
          <div class="feat-text">
            <strong>Auto Signals</strong>
            <span>BUY on pullback &amp; 20% crash. SELL at 30% profit.</span>
          </div>
        </div>
        <div class="feat-item">
          <span class="feat-icon">⚙️</span>
          <div class="feat-text">
            <strong>Custom Strategy</strong>
            <span>Set your own %, targets &amp; max buys per account.</span>
          </div>
        </div>
      </div>
    </div>

    <div class="left-bottom">
      <div class="plan-pill">🆓 FREE PLAN — No credit card needed</div>
      <div class="left-contact">
        FinOps Digital Solutions · Chennai<br>
        <a href="https://finopsdigital.com" target="_blank">finopsdigital.com</a>
      </div>
      <a href="https://wa.me/919600034839?text=Hi%2C+I+want+to+know+more+about+STAS" target="_blank" class="btn-wa-sm">
        💬 WhatsApp: +91 96000 34839
      </a>
    </div>

  </div>

  <!-- ══ RIGHT — LOGIN / REGISTER ══════════════════════ -->
  <div class="split-right">

    <div class="right-title">Welcome to STAS</div>
    <div class="right-sub">Sign in to your account or create a new one</div>

    <div class="tab-btns">
      <button class="tab-btn active" id="tabLogin"    onclick="switchTab('login')">Login</button>
      <button class="tab-btn"        id="tabRegister" onclick="switchTab('register')">Register</button>
    </div>

    <!-- Login Form -->
    <div id="loginForm">
      <div class="alert alert-danger py-2 px-3 mb-3" id="loginError" style="font-size:13px;"></div>
      <div class="mb-3">
        <label class="form-label">Email Address</label>
        <input type="email" id="loginEmail" class="form-control" placeholder="you@email.com" autocomplete="username">
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" id="loginPass" class="form-control" placeholder="Enter password" autocomplete="current-password">
      </div>
      <button class="btn-login" id="loginBtn" onclick="doLogin()">Login</button>
    </div>

    <!-- Register Form -->
    <div id="registerForm" style="display:none;">
      <div class="alert alert-danger py-2 px-3 mb-3" id="registerError" style="font-size:13px;"></div>
      <div class="mb-2">
        <label class="form-label">Full Name</label>
        <input type="text" id="regName" class="form-control" placeholder="Your name">
      </div>
      <div class="mb-2">
        <label class="form-label">Email Address</label>
        <input type="email" id="regEmail" class="form-control" placeholder="you@email.com">
      </div>
      <div class="mb-2">
        <label class="form-label">Password</label>
        <input type="password" id="regPass" class="form-control" placeholder="Min 6 characters">
      </div>
      <div class="mb-2">
        <label class="form-label">Phone (optional)</label>
        <input type="tel" id="regPhone" class="form-control" placeholder="+91 XXXXX XXXXX">
      </div>
      <!-- CAPTCHA -->
      <label class="form-label">Security Check</label>
      <div class="captcha-wrap">
        <span class="captcha-q" id="captchaText">? + ? = ?</span>
        <input type="number" id="captchaInput" class="form-control captcha-in" placeholder="Answer" min="0" max="20">
        <button class="captcha-btn" onclick="generateCaptcha()" title="New question">🔄</button>
      </div>
      <button class="btn-login" id="registerBtn" onclick="doRegister()">Create Account — FREE</button>
      <p class="free-note">✅ Free plan · No credit card needed</p>
    </div>

    <p class="footer-copy">© 2025 FinOps Digital Solutions, Chennai</p>
  </div>

</div><!-- end split-wrap -->

<script>
const API='api/index.php';
let _cA=0,_cB=0,_cAns=0;
function generateCaptcha(){
  _cA=Math.floor(Math.random()*9)+1;_cB=Math.floor(Math.random()*9)+1;_cAns=_cA+_cB;
  document.getElementById('captchaText').textContent=_cA+' + '+_cB+' = ?';
  document.getElementById('captchaInput').value='';
}
generateCaptcha();

function switchTab(tab){
  document.getElementById('loginForm').style.display   =tab==='login'?'':'none';
  document.getElementById('registerForm').style.display=tab==='register'?'':'none';
  document.getElementById('tabLogin').classList.toggle('active',tab==='login');
  document.getElementById('tabRegister').classList.toggle('active',tab==='register');
  document.getElementById('loginError').style.display='none';
  document.getElementById('registerError').style.display='none';
  if(tab==='register')generateCaptcha();
}

function setLoading(id,on){
  const b=document.getElementById(id);b.disabled=on;
  b.innerHTML=on?'<span class="spinner-border spinner-border-sm me-2"></span>Please wait...':(id==='loginBtn'?'Login':'Create Account — FREE');
}

async function doLogin(){
  const email=document.getElementById('loginEmail').value.trim();
  const pass=document.getElementById('loginPass').value.trim();
  const err=document.getElementById('loginError');err.style.display='none';
  if(!email||!pass){err.textContent='Email and password required.';err.style.display='';return;}
  setLoading('loginBtn',true);
  try{
    const r=await fetch(`${API}?action=login`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password:pass})});
    const d=await r.json();
    if(d.status==='ok'){window.location.href='index.php';}
    else{err.textContent=d.message||'Login failed';err.style.display='';setLoading('loginBtn',false);}
  }catch(e){err.textContent='Network error.';err.style.display='';setLoading('loginBtn',false);}
}

async function doRegister(){
  const name=document.getElementById('regName').value.trim();
  const email=document.getElementById('regEmail').value.trim();
  const pass=document.getElementById('regPass').value.trim();
  const phone=document.getElementById('regPhone').value.trim();
  const cap=parseInt(document.getElementById('captchaInput').value);
  const err=document.getElementById('registerError');
  err.className='alert alert-danger py-2 px-3 mb-3';err.style.display='none';
  if(!name||!email||!pass){err.textContent='Name, email and password required.';err.style.display='';return;}
  if(pass.length<6){err.textContent='Password min 6 characters.';err.style.display='';return;}
  if(isNaN(cap)||cap!==_cAns){err.textContent='❌ Wrong! '+_cA+' + '+_cB+' = '+_cAns+'. Try again.';err.style.display='';generateCaptcha();return;}
  setLoading('registerBtn',true);
  try{
    const r=await fetch(`${API}?action=register`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,email,password:pass,phone})});
    const d=await r.json();
    if(d.status==='ok'){
      err.className='alert alert-success py-2 px-3 mb-3';err.textContent='✅ Account created! Redirecting...';err.style.display='';
      setTimeout(()=>window.location.href='index.php',1200);
    }else{err.textContent=d.message||'Registration failed';err.style.display='';setLoading('registerBtn',false);generateCaptcha();}
  }catch(e){err.textContent='Network error.';err.style.display='';setLoading('registerBtn',false);}
}

document.addEventListener('keydown',e=>{
  if(e.key==='Enter'){document.getElementById('loginForm').style.display!=='none'?doLogin():doRegister();}
});
</script>
</body>
</html>
