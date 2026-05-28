/* ===== nav drawer ===== */
(function(){
  var b=document.getElementById('burger'),d=document.getElementById('drawer'),s=document.getElementById('scrim');
  function open(){d&&d.classList.add('show');s&&s.classList.add('show');}
  function close(){d&&d.classList.remove('show');s&&s.classList.remove('show');}
  if(b)b.onclick=function(){d.classList.contains('show')?close():open();};
  if(s)s.onclick=close;
})();

/* ===== Active-time tracker + idle lock =====
   Counts engaged seconds on the current screen. If no activity for IDLE_LIMIT,
   it shows a lock overlay and STOPS counting (idle time is not recorded).
   Every FLUSH_EVERY seconds (and on page-hide) the un-sent active seconds are
   POSTed to api/track.php so Reports can show accurate, idle-free time.        */
(function(){
  var IDLE_LIMIT = 300;            // seconds (mirrors IDLE_LIMIT_SEC in config.php)
  var FLUSH_EVERY = 20;            // send accumulated seconds this often
  var body    = document.body;
  var screen  = body.getAttribute('data-screen') || 'app';
  var subject = body.getAttribute('data-subject') || '';
  var chapter = body.getAttribute('data-chapter') || '';
  var active = 0, sinceFlush = 0, lastActivity = Date.now(), locked = false;

  // build lock overlay
  var lock = document.createElement('div');
  lock.className = 'lock';
  lock.innerHTML = '<div class="inner"><h2>Paused</h2><p>Screen locked after 5 minutes of inactivity. Tap to resume — idle time is not counted.</p><button class="btn" id="resumeBtn">Resume</button></div>';
  document.body.appendChild(lock);
  document.getElementById('resumeBtn').onclick = function(){ unlock(); };

  function lockScreen(){ locked=true; lock.classList.add('show'); flush(); }
  function unlock(){ locked=false; lastActivity=Date.now(); lock.classList.remove('show'); }

  ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(ev){
    window.addEventListener(ev, function(){ lastActivity=Date.now(); if(locked) unlock(); }, {passive:true});
  });

  function flush(useBeacon){
    if(sinceFlush < 1) return;
    var fd = new FormData();
    fd.append('screen', screen);
    fd.append('seconds', sinceFlush);
    if(subject) fd.append('subject', subject);
    if(chapter) fd.append('chapter', chapter);
    sinceFlush = 0;
    if(useBeacon && navigator.sendBeacon){ navigator.sendBeacon('api/track.php', fd); }
    else { fetch('api/track.php', {method:'POST', body:fd, keepalive:true}).catch(function(){}); }
  }

  setInterval(function(){
    var idle = (Date.now()-lastActivity)/1000;
    if(idle >= IDLE_LIMIT){ if(!locked) lockScreen(); return; }
    if(!locked && document.visibilityState==='visible'){ active += 1; sinceFlush += 1; }
    if(sinceFlush >= FLUSH_EVERY){ flush(false); }
  }, 1000);

  document.addEventListener('visibilitychange', function(){ if(document.visibilityState==='hidden') flush(true); });
  window.addEventListener('pagehide', function(){ flush(true); });

  // expose for debugging
  window.__activeSeconds = function(){ return active; };
  window.__screenName    = function(){ return screen; };
})();
