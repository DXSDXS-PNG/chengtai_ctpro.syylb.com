(function () {
  'use strict';

  var LAYOUT_CLASS = 'ct-resource-layout';
  var ASIDE_CLASS = 'ct-resource-aside';
  var DASHBOARD_HASH = '#/dashboard';

  var ITEMS = [
    {
      title: '承台会员版',
      desc: '更适合运营的版本，精细化运营！',
      href: 'https://ctpro.syylb.com/',
      color: '#F97316',
      icon: 'gift'
    },
    {
      title: 'GitHub地址',
      desc: '前往 GitHub 查看项目',
      href: 'https://github.com/DXSDXS-PNG/chengtai_ctpro.syylb.com',
      color: '#333333',
      icon: 'code'
    },
    {
      title: '加入交流群',
      desc: '加入用户交流群，交流使用经验',
      href: 'https://csd.syylb.com/qrcode.jpg',
      color: '#22C55E',
      icon: 'chat'
    }
  ];

  var ICONS = {
    gift: '<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
    chat: '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'
  };

  function iconSvg(name, color) {
    var path = ICONS[name] || ICONS.link;
    return '<svg viewBox="0 0 24 24" width="45" height="45" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="12" fill="' + color + '"/>' +
      '<g transform="matrix(0.65 0 0 0.65 4.2 4.2)" fill="none" stroke="#fff" ' +
      'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + path + '</g>' +
      '</svg>';
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function buildAside() {
    var aside = document.createElement('aside');
    aside.className = ASIDE_CLASS;
    var html = '';
    for (var i = 0; i < ITEMS.length; i++) {
      var item = ITEMS[i];
      html += '<a class="ct-resource-card" href="' + escapeHtml(item.href) +
        '" target="_blank" rel="noopener noreferrer">' +
        '<span class="ct-resource-icon">' + iconSvg(item.icon, item.color) + '</span>' +
        '<span class="ct-resource-info">' +
        '<span class="ct-resource-title">' + escapeHtml(item.title) + '</span>' +
        '<span class="ct-resource-desc">' + escapeHtml(item.desc) + '</span>' +
        '</span>' +
        '<span class="ct-resource-go"></span>' +
        '</a>';
    }
    aside.innerHTML = html;
    return aside;
  }

  function currentHash() {
    return (location.hash || '').split('?')[0];
  }

  function sync() {
    var host = document.querySelector('.console-content');
    var aside = document.querySelector('.' + ASIDE_CLASS);
    var want = currentHash() === DASHBOARD_HASH;

    if (aside && (!want || !host || aside.parentNode !== host)) {
      if (aside.parentNode) aside.parentNode.removeChild(aside);
      aside = null;
    }

    if (!host) return;

    if (!want) {
      host.classList.remove(LAYOUT_CLASS);
      return;
    }

    if (!aside) host.appendChild(buildAside());
    host.classList.add(LAYOUT_CLASS);
  }

  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    var run = function () {
      scheduled = false;
      sync();
    };
    if (window.requestAnimationFrame) requestAnimationFrame(run);
    else setTimeout(run, 16);
  }

  function start() {
    sync();
    window.addEventListener('hashchange', schedule);
    if (window.MutationObserver) {
      new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
