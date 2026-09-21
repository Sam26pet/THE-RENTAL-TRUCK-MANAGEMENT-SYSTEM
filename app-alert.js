(function () {
  const style = document.createElement('style');
  style.textContent = `
    .app-alert-backdrop {
      position: fixed;
      inset: 0;
      z-index: 10000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(5, 12, 28, .58);
      backdrop-filter: blur(7px);
      animation: appAlertFadeIn .18s ease-out;
    }
    .app-alert {
      width: min(100%, 440px);
      overflow: hidden;
      border: 1px solid rgba(143, 224, 207, .24);
      border-radius: 14px;
      background: #102f38;
      color: #e7f6f2;
      box-shadow: 0 28px 80px rgba(0, 0, 0, .5), 0 0 0 1px rgba(255, 255, 255, .04);
      animation: appAlertSlideIn .22s ease-out;
    }
    .app-alert-backdrop.is-closing { opacity: 0; transition: opacity .16s ease; }
    .app-alert-backdrop.manual-close .app-alert-timer { display: none; }
    .app-alert-bar { height: 4px; background: #73d6c2; }
    .app-alert.is-error .app-alert-bar { background: #ed765f; }
    .app-alert.is-success .app-alert-bar { background: #f3bd61; }
    .app-alert-content { display: flex; gap: 14px; padding: 24px 24px 14px; }
    .app-alert-icon {
      display: grid;
      flex: 0 0 42px;
      place-items: center;
      width: 42px;
      height: 42px;
      border-radius: 11px;
      background: rgba(115, 214, 194, .14);
      color: #73d6c2;
      font-size: 22px;
      font-weight: 700;
    }
    .is-error .app-alert-icon { background: rgba(237, 118, 95, .16); color: #ed765f; }
    .is-success .app-alert-icon { background: rgba(243, 189, 97, .16); color: #f3bd61; }
    .app-alert-title { margin: 0 0 5px; color: #e7f6f2; font: 700 18px/1.3 "Segoe UI", Arial, sans-serif; }
    .app-alert-message { margin: 0; color: #a9c7c4; font: 15px/1.5 "Segoe UI", Arial, sans-serif; white-space: pre-line; }
    .app-alert-actions { display: flex; justify-content: flex-end; padding: 8px 24px 18px; }
    .app-alert-close {
      border: 0;
      border-radius: 8px;
      padding: 9px 18px;
      background: #247f74;
      color: #fff;
      font: 700 14px "Segoe UI", Arial, sans-serif;
      cursor: pointer;
      transition: filter .15s ease, transform .15s ease;
    }
    .app-alert-close:hover { filter: brightness(1.08); transform: translateY(-1px); }
    .app-alert-close:focus-visible { outline: 3px solid rgba(25, 118, 210, .3); outline-offset: 2px; }
    .is-error .app-alert-close { background: #c9574a; }
    .is-success .app-alert-close { background: #b28d42; color: #17292d; }
    .app-alert-timer { height: 3px; background: rgba(115, 214, 194, .12); }
    .app-alert-timer::before { content: ''; display: block; width: 100%; height: 100%; background: #73d6c2; transform-origin: left; animation: appAlertCountdown var(--app-alert-duration) linear forwards; }
    .is-error .app-alert-timer::before { background: #ed765f; }
    .is-success .app-alert-timer::before { background: #f3bd61; }
    @keyframes appAlertFadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes appAlertSlideIn { from { transform: translateY(-14px); } to { transform: translateY(0); } }
    @keyframes appAlertCountdown { from { transform: scaleX(1); } to { transform: scaleX(0); } }
    @media (prefers-reduced-motion: reduce) { .app-alert-backdrop, .app-alert { animation: none; } .app-alert-timer::before { animation: none; } }
    @media (max-width: 480px) { .app-alert-content { padding: 18px 18px 10px; } .app-alert-actions { padding: 8px 18px 15px; } }
  `;
  document.head.appendChild(style);

  window.showAppAlert = function (message, title, manualClose = false) {
    document.querySelectorAll('.app-alert-backdrop').forEach((item) => item.remove());
    const text = String(message ?? '');
    const isError = /error|invalid|required|failed|unable|already|unavailable|cannot|wrong|not found/i.test(text);
    const isSuccess = /success|submitted|registered|completed|saved|updated|available/i.test(text);
    const kind = isError ? 'is-error' : (isSuccess ? 'is-success' : 'is-info');
    const heading = String(title || (isError ? 'Please check this' : (isSuccess ? 'Success' : 'Notice')));
    const icon = isError ? '!' : (isSuccess ? '✓' : 'i');
    const duration = manualClose ? 0 : (isError ? 4800 : 3400);
    const backdrop = document.createElement('div');
    backdrop.className = 'app-alert-backdrop' + (manualClose ? ' manual-close' : '');
    backdrop.style.setProperty('--app-alert-duration', `${duration || 3400}ms`);
    backdrop.innerHTML = `
      <div class="app-alert ${kind}" role="alertdialog" aria-modal="true">
        <div class="app-alert-bar"></div>
        <div class="app-alert-content">
          <div class="app-alert-icon" aria-hidden="true">${icon}</div>
          <div><h2 class="app-alert-title">${heading}</h2><p class="app-alert-message"></p></div>
        </div>
        <div class="app-alert-actions"><button class="app-alert-close" type="button">OK</button></div>
        <div class="app-alert-timer" aria-hidden="true"></div>
      </div>`;
    backdrop.querySelector('.app-alert-title').textContent = heading;
    backdrop.querySelector('.app-alert').setAttribute('aria-label', heading);
    backdrop.querySelector('.app-alert-message').textContent = text.replace(/^[✅❌⚠️ℹ️]\s*/u, '');
    let dismissTimer;
    const close = () => {
      clearTimeout(dismissTimer);
      backdrop.classList.add('is-closing');
      setTimeout(() => backdrop.remove(), 160);
    };
    backdrop.querySelector('.app-alert-close').addEventListener('click', close);
    backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
    backdrop.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    document.body.appendChild(backdrop);
    backdrop.querySelector('.app-alert-close').focus();
    if (duration) dismissTimer = setTimeout(close, duration);
  };

  window.alert = window.showAppAlert;
})();
