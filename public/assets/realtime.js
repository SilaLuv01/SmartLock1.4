(() => {
  const marker = document.querySelector('[data-realtime]');
  if (!marker) return;
  let revision = marker.dataset.revision, dirty = false, timer;
  document.addEventListener('input', () => { dirty = true; });
  document.addEventListener('change', () => { dirty = true; });
  async function poll() {
    if (document.hidden) return;
    try {
      const response = await fetch(marker.dataset.url, {cache: 'no-store', credentials: 'same-origin'});
      if (response.status === 401) { location.reload(); return; }
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (data.revision !== revision) {
        if (!dirty) { location.reload(); return; }
        marker.textContent = 'มีข้อมูลใหม่ · กดอัปเดตเมื่อกรอกข้อมูลเสร็จ';
        marker.onclick = () => location.reload();
      } else marker.textContent = 'อัปเดตอัตโนมัติทุก 2 วินาที';
    } catch (_) { marker.textContent = 'ขาดการเชื่อมต่อเว็บไซต์ · กำลังลองใหม่'; }
  }
  document.addEventListener('visibilitychange', () => {
    clearInterval(timer);
    if (!document.hidden) { poll(); timer = setInterval(poll, 2000); }
  });
  timer = setInterval(poll, 2000);
})();
