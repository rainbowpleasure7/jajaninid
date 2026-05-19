// JajaninId - Main Application Logic (Connected to Backend API)

const API_URL = '/backend/api.php';
const AUTH_URL = '/backend/auth.php';

// ===== SESSION & AUTH =====
let currentUser = null;

async function checkSession() {
  try {
    const res = await fetch(`${AUTH_URL}?action=session`);
    const data = await res.json();
    currentUser = data.logged_in ? data.user : null;
    updateNavbar();
  } catch (e) { currentUser = null; }
}

function updateNavbar() {
  const navLinks = document.querySelector('.nav-links');
  if (!navLinks) return;
  if (currentUser) {
    navLinks.innerHTML = `
      <a href="explore.html">🔍 Explore</a>
      ${currentUser.role === 'creator' ? '<a href="dashboard.html">📊 Dashboard</a>' : ''}
      <span style="font-family:var(--font-comic);font-weight:700">👋 ${currentUser.name}</span>
      <a href="${AUTH_URL}?action=logout" class="btn btn-danger btn-sm">Logout 🚪</a>
    `;
  }
}

function loginWithGoogle() {
  window.location.href = `${AUTH_URL}?action=login`;
}

// ===== GIFT FLOW =====
let selectedGift = null;
let selectedPayment = null;

function selectGift(amount, el) {
  document.querySelectorAll('.gift-option').forEach(o => o.classList.remove('selected'));
  if (el) el.classList.add('selected');
  selectedGift = amount;
  const customInput = document.getElementById('customAmount');
  if (customInput) customInput.value = '';
  updateGiftSummary();
}

function onCustomAmount(val) {
  const num = parseInt(val.replace(/\D/g, '')) || 0;
  document.querySelectorAll('.gift-option').forEach(o => o.classList.remove('selected'));
  selectedGift = num;
  updateGiftSummary();
}

function selectPayment(method, el) {
  document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
  if (el) el.classList.add('selected');
  selectedPayment = method;
}

function updateGiftSummary() {
  const amount = selectedGift || 0;
  const fee = Math.ceil(amount * 0.01);
  const total = amount + fee;
  const el = (id) => document.getElementById(id);
  if (el('summaryAmount')) el('summaryAmount').textContent = formatRupiah(amount);
  if (el('summaryFee')) el('summaryFee').textContent = formatRupiah(fee);
  if (el('summaryTotal')) el('summaryTotal').textContent = formatRupiah(total);
}

async function sendGift() {
  if (!selectedGift || selectedGift < 1000) {
    showModal('Oops!', 'Pilih nominal gift minimal Rp 1.000 ya! 😅', 'warning');
    return;
  }
  if (!selectedPayment) {
    showModal('Oops!', 'Pilih metode pembayaran dulu ya! 💳', 'warning');
    return;
  }

  if (!currentUser) {
    showModal('Oops!', 'Kamu harus login atau buat akun dulu untuk mengirim gift! 🔐', 'warning');
    setTimeout(() => { window.location.href = 'login.html'; }, 2000);
    return;
  }

  const amount = selectedGift || 0;
  const fee = Math.ceil(amount * 0.01);
  const total = amount + fee;

  showPaymentModal(amount, fee, total, selectedPayment);
}

let paymentTimerInterval = null;

function showPaymentModal(amount, fee, total, method) {
  let overlay = document.getElementById('paymentModalOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'paymentModalOverlay';
    overlay.className = 'modal-overlay';
    document.body.appendChild(overlay);
  }

  let instructions = '';
  if (method === 'QRIS') {
    instructions = `
      <div style="background:#eee; width:150px; height:150px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; border:2px dashed var(--ink);">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=DUMMY_QRIS_JajaninId" alt="QRIS" style="width:100%; height:100%;">
      </div>
      <p style="font-size:0.85rem">Scan QR di atas dengan aplikasi pembayaran Anda.</p>
    `;
  } else {
    // Generate random account number
    const randomAcc = Math.floor(Math.random() * 9000000000) + 1000000000;
    instructions = `
      <div style="background:var(--bg); padding:10px; border-radius:4px; border:2px dashed var(--ink); margin-bottom:12px;">
        <p style="margin:0; font-size:0.85rem">Transfer ke Rekening ${method}:</p>
        <h3 style="margin:4px 0; font-family:var(--font-comic); letter-spacing:2px;">${randomAcc}</h3>
        <p style="margin:0; font-size:0.75rem">a.n. JajaninId Indonesia</p>
      </div>
    `;
  }

  overlay.innerHTML = `
    <div class="modal">
      <h2 style="margin-top:0">Konfirmasi Pembayaran 💳</h2>
      <p style="font-size:0.9rem; margin-bottom:16px;">Selesaikan pembayaran Anda dalam batas waktu berikut:</p>
      
      <div style="font-size:2rem; font-weight:bold; color:var(--danger); font-family:var(--font-comic); margin-bottom:16px;" id="paymentTimer">
        05:00
      </div>

      ${instructions}

      <div style="text-align:left; background:#fafafa; padding:12px; border-radius:var(--radius); border:1px solid #ccc; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
          <span>Nominal Gift:</span> <strong>${formatRupiah(amount)}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
          <span>Biaya Admin:</span> <strong>${formatRupiah(fee)}</strong>
        </div>
        <hr style="border:0; border-top:1px dashed #ccc; margin:8px 0;">
        <div style="display:flex; justify-content:space-between; font-size:1.1rem; color:var(--primary);">
          <span>Total Tagihan:</span> <strong>${formatRupiah(total)}</strong>
        </div>
      </div>

      <div style="display:flex; gap:10px;">
        <button class="btn btn-secondary" style="flex:1" onclick="cancelPayment()">Batal</button>
        <button class="btn btn-primary" style="flex:2" onclick="confirmPaymentAction()">Saya Sudah Bayar ✅</button>
      </div>
    </div>
  `;
  overlay.classList.add('active');

  // Start 5-minute countdown
  let timeLeft = 300; // 5 minutes in seconds
  clearInterval(paymentTimerInterval);
  paymentTimerInterval = setInterval(() => {
    timeLeft--;
    const m = Math.floor(timeLeft / 60).toString().padStart(2, '0');
    const s = (timeLeft % 60).toString().padStart(2, '0');
    const timerEl = document.getElementById('paymentTimer');
    if (timerEl) timerEl.textContent = `${m}:${s}`;

    if (timeLeft <= 0) {
      clearInterval(paymentTimerInterval);
      cancelPayment();
      showModal('Waktu Habis ⏱️', 'Waktu pembayaran telah habis. Silakan ulangi kembali.', 'warning');
    }
  }, 1000);
}

function cancelPayment() {
  clearInterval(paymentTimerInterval);
  const overlay = document.getElementById('paymentModalOverlay');
  if (overlay) overlay.classList.remove('active');
}

async function confirmPaymentAction() {
  cancelPayment(); // Close modal and stop timer
  
  const params = new URLSearchParams(window.location.search);
  const username = params.get('u') || 'gamingzone';
  const message = document.getElementById('giftMessage')?.value || '';

  try {
    const res = await fetch(`${API_URL}?action=send_gift`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        creator_username: username,
        gift_amount: selectedGift,
        message: message,
        payment_method: selectedPayment,
        fan_name: currentUser ? currentUser.name : 'Anonymous',
      })
    });
    const data = await res.json();

    if (data.success) {
      showModal('BOOM! 🎉',
        `Pembayaran Berhasil! Gift ${formatRupiah(data.gift_amount)} telah dikirim ke kreator!<br>` +
        `<small>Order ID: ${data.order_id}</small>`, 'success');
      // Reset
      selectedGift = null; selectedPayment = null;
      document.querySelectorAll('.gift-option, .payment-option').forEach(o => o.classList.remove('selected'));
      if (document.getElementById('giftMessage')) document.getElementById('giftMessage').value = '';
      if (document.getElementById('customAmount')) document.getElementById('customAmount').value = '';
      updateGiftSummary();
      // Reload supporters
      setTimeout(() => loadCreatorProfile(), 1500);
    } else {
      showModal('Oops!', data.error || 'Terjadi kesalahan', 'warning');
    }
  } catch (e) {
    showModal('Error', 'Gagal memproses pembayaran. Pastikan server berjalan.', 'warning');
  }
}

// ===== MODAL =====
function showModal(title, message, type) {
  let overlay = document.getElementById('modalOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'modalOverlay';
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `<div class="modal"><div class="burst" id="modalBurst"></div><h2 id="modalTitle"></h2><p id="modalMsg"></p><br><button class="btn btn-primary" onclick="closeModal()">OK!</button></div>`;
    document.body.appendChild(overlay);
  }
  const bursts = { success: '🎉', warning: '⚠️', info: 'ℹ️' };
  document.getElementById('modalBurst').textContent = bursts[type] || '💬';
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalMsg').innerHTML = message;
  overlay.classList.add('active');
}

function closeModal() {
  const overlay = document.getElementById('modalOverlay');
  if (overlay) overlay.classList.remove('active');
}

// ===== EXPLORE PAGE =====
async function renderCreators(filter) {
  const grid = document.getElementById('creatorGrid');
  if (!grid) return;
  grid.innerHTML = '<p style="text-align:center;font-family:var(--font-comic)">Memuat kreator...</p>';

  try {
    const params = filter && filter !== 'Semua' ? `&category=${filter}` : '';
    const res = await fetch(`${API_URL}?action=creators${params}`);
    const data = await res.json();

    const emojis = { Art:'🎨', Gaming:'🎮', Cooking:'🍳', Music:'🎵', Tech:'📱', Comedy:'😂', Fitness:'💪', Travel:'✈️', Other:'⭐' };

    grid.innerHTML = data.creators.map(c => `
      <a href="creator.html?u=${c.username}" class="comic-card creator-card">
        <div class="avatar">${c.avatar_url ? `<img src="${c.avatar_url}" alt="${c.display_name}">` : (emojis[c.category] || '⭐')}</div>
        <h3>${c.display_name}</h3>
        <span class="category">${c.category}</span>
        <p class="supporters">👥 ${(c.supporters || 0).toLocaleString()} supporters</p>
        <button class="btn btn-primary btn-sm" style="margin:0 auto 16px">Kirim Gift 🎁</button>
      </a>
    `).join('');
  } catch (e) {
    grid.innerHTML = CREATORS_FALLBACK.map(c => `
      <a href="creator.html?u=${c.username}" class="comic-card creator-card">
        <div class="avatar">${c.avatar}</div><h3>${c.name}</h3>
        <span class="category">${c.category}</span>
        <p class="supporters">👥 ${c.supporters.toLocaleString()} supporters</p>
        <button class="btn btn-primary btn-sm" style="margin:0 auto 16px">Kirim Gift 🎁</button>
      </a>`).join('');
  }
}

function filterCreators(cat, el) {
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
  renderCreators(cat);
}

async function searchCreators(query) {
  const grid = document.getElementById('creatorGrid');
  if (!grid) return;
  try {
    const res = await fetch(`${API_URL}?action=creators&search=${encodeURIComponent(query)}`);
    const data = await res.json();
    const emojis = { Art:'🎨', Gaming:'🎮', Cooking:'🍳', Music:'🎵', Tech:'📱', Comedy:'😂', Fitness:'💪', Travel:'✈️' };
    grid.innerHTML = data.creators.map(c => `
      <a href="creator.html?u=${c.username}" class="comic-card creator-card">
        <div class="avatar">${c.avatar_url ? `<img src="${c.avatar_url}">` : (emojis[c.category] || '⭐')}</div>
        <h3>${c.display_name}</h3><span class="category">${c.category}</span>
        <p class="supporters">👥 ${(c.supporters||0).toLocaleString()} supporters</p>
        <button class="btn btn-primary btn-sm" style="margin:0 auto 16px">Kirim Gift 🎁</button>
      </a>`).join('');
  } catch (e) { /* fallback handled */ }
}

// ===== CREATOR PROFILE =====
const GIFT_PRESETS = [
  { amount: 5000, label: "High Five!", emoji: "✋" },
  { amount: 10000, label: "Traktir Kopi", emoji: "☕" },
  { amount: 25000, label: "Super Fan!", emoji: "⭐" },
  { amount: 50000, label: "Hero Support!", emoji: "🦸" },
  { amount: 100000, label: "MEGA GIFT!", emoji: "💎" },
  { amount: 250000, label: "LEGENDARY!", emoji: "👑" },
];
const PAYMENT_METHODS = [
  { id: "GoPay", name: "GoPay", icon: "💚" },
  { id: "DANA", name: "DANA", icon: "💙" },
  { id: "OVO", name: "OVO", icon: "💜" },
  { id: "QRIS", name: "QRIS", icon: "📱" },
];

// Fallback data jika server mati
const CREATORS_FALLBACK = [
  { username: "gamingzone", name: "Gaming Zone", avatar: "🎮", category: "Gaming", supporters: 3200 },
  { username: "artstudio_id", name: "Art Studio ID", avatar: "🎨", category: "Art", supporters: 1240 },
  { username: "comedynight", name: "Comedy Night", avatar: "😂", category: "Comedy", supporters: 4500 },
  { username: "musikindo", name: "Musik Indo", avatar: "🎵", category: "Music", supporters: 2100 },
];

async function loadCreatorProfile() {
  const params = new URLSearchParams(window.location.search);
  const username = params.get('u');
  if (!username) return;

  const el = (id) => document.getElementById(id);

  try {
    const res = await fetch(`${API_URL}?action=creator&username=${username}`);
    const data = await res.json();
    const c = data.creator;
    const emojis = { Art:'🎨', Gaming:'🎮', Cooking:'🍳', Music:'🎵', Tech:'📱', Comedy:'😂', Fitness:'💪', Travel:'✈️' };

    if (el('creatorAvatar')) {
      el('creatorAvatar').innerHTML = c.avatar_url ? `<img src="${c.avatar_url}">` : (emojis[c.category] || '⭐');
    }
    if (el('creatorName')) el('creatorName').textContent = c.display_name;
    if (el('creatorBio')) el('creatorBio').textContent = c.bio;
    if (el('creatorSupporters')) el('creatorSupporters').textContent = (c.supporters || 0).toLocaleString();
    if (el('creatorGifts')) el('creatorGifts').textContent = formatRupiah(c.total_earned || 0);

    // Render supporters from DB
    const supList = el('supportersList');
    if (supList && data.recent_supporters) {
      supList.innerHTML = data.recent_supporters.map(s => {
        const timeAgo = getTimeAgo(s.created_at);
        return `<div class="supporter-item">
          <div class="sup-avatar">😊</div>
          <div class="speech-bubble sup-bubble">
            <span class="sup-name">${s.fan_display_name}</span> · <span class="sup-amount">${formatRupiah(s.gift_amount)}</span>
            <span style="float:right;font-size:.75rem;opacity:.5">${timeAgo}</span>
            ${s.message ? `<p class="sup-msg">"${s.message}"</p>` : ''}
          </div>
        </div>`;
      }).join('');
    }
  } catch (e) { /* Fallback to static */ }

  // Always render gift presets & payment methods
  const giftGrid = el('giftGrid');
  if (giftGrid) {
    giftGrid.innerHTML = GIFT_PRESETS.map(g => `
      <div class="comic-card gift-option" onclick="selectGift(${g.amount}, this)">
        <div class="emoji">${g.emoji}</div>
        <div class="amount">${formatRupiah(g.amount)}</div>
        <div class="label">${g.label}</div>
      </div>`).join('');
  }
  const payGrid = el('paymentGrid');
  if (payGrid) {
    payGrid.innerHTML = PAYMENT_METHODS.map(p => `
      <div class="comic-card payment-option" onclick="selectPayment('${p.id}', this)">
        <span class="pay-icon">${p.icon}</span><span class="pay-name">${p.name}</span>
      </div>`).join('');
  }
  updateGiftSummary();
}

// ===== DASHBOARD =====
async function loadDashboard() {
  const tbody = document.getElementById('giftTableBody');
  if (!tbody) return;

  try {
    const res = await fetch(`${API_URL}?action=dashboard`);
    const data = await res.json();

    if (data.error) {
      tbody.innerHTML = `<tr><td colspan="7" style="text-align:center">${data.error}</td></tr>`;
      return;
    }

    // Update stat cards
    const el = (id) => document.getElementById(id);
    if (el('statEarnings')) el('statEarnings').textContent = formatRupiah(data.profile.total_earned);
    if (el('statBalance')) el('statBalance').textContent = formatRupiah(data.profile.available_balance);
    if (el('statSupporters')) el('statSupporters').textContent = (data.stats.unique_supporters || 0).toLocaleString();
    if (el('statMonthly')) el('statMonthly').textContent = data.monthly_gifts;

    // Render transactions
    tbody.innerHTML = data.transactions.map(g => `
      <tr>
        <td><strong>${g.order_id}</strong></td>
        <td>${g.fan_display_name}</td>
        <td>${formatRupiah(g.gift_amount)}</td>
        <td>${formatRupiah(g.platform_fee)}</td>
        <td>${g.payment_method}</td>
        <td><span class="badge ${g.status==='success'?'badge-success':'badge-pending'}">${g.status}</span></td>
        <td>${formatDate(g.created_at)}</td>
      </tr>`).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;font-family:var(--font-comic)">Server belum berjalan. Jalankan: php -S localhost:8000</td></tr>';
  }
}

async function requestWithdraw() {
  const amount = parseInt(document.getElementById('withdrawAmount')?.value || 0);
  const method = document.getElementById('withdrawMethod')?.value || '';
  const account = document.getElementById('withdrawAccount')?.value || '';

  if (!amount || !method || !account) {
    showModal('Oops!', 'Lengkapi semua field pencairan', 'warning');
    return;
  }

  const isConfirmed = confirm(`Apakah Anda yakin ingin menarik uang sebesar ${formatRupiah(amount)} ke rekening ${method} (${account})?`);
  if (!isConfirmed) return;

  try {
    const res = await fetch(`${API_URL}?action=withdraw`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount, method, destination_account: account })
    });
    const data = await res.json();
    if (data.success) {
      showModal('Diproses! ⏳', data.message, 'info');
      loadDashboard();
    } else {
      showModal('Oops!', data.error, 'warning');
    }
  } catch (e) {
    showModal('Error', 'Gagal memproses pencairan', 'warning');
  }
}

// ===== LANDING PAGE =====
async function renderTopCreators() {
  const grid = document.getElementById('topCreators');
  if (!grid) return;
  try {
    const res = await fetch(`${API_URL}?action=creators`);
    const data = await res.json();
    const emojis = { Art:'🎨', Gaming:'🎮', Cooking:'🍳', Music:'🎵', Tech:'📱', Comedy:'😂', Fitness:'💪', Travel:'✈️' };
    const top = data.creators.slice(0, 4);
    grid.innerHTML = top.map(c => `
      <a href="creator.html?u=${c.username}" class="comic-card creator-card">
        <div class="avatar">${c.avatar_url ? `<img src="${c.avatar_url}">` : (emojis[c.category]||'⭐')}</div>
        <h3>${c.display_name}</h3><span class="category">${c.category}</span>
        <p class="supporters">👥 ${(c.supporters||0).toLocaleString()} supporters</p>
        <button class="btn btn-primary btn-sm" style="margin:0 auto 16px">Kirim Gift 🎁</button>
      </a>`).join('');
  } catch (e) { /* Use fallback */ }
}

// ===== UTILITIES =====
function formatRupiah(num) {
  return "Rp " + (num || 0).toLocaleString("id-ID");
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  return d.toLocaleDateString('id-ID', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function getTimeAgo(dateStr) {
  const now = new Date();
  const then = new Date(dateStr);
  const diff = Math.floor((now - then) / 1000);
  if (diff < 60) return 'Baru saja';
  if (diff < 3600) return `${Math.floor(diff/60)} menit lalu`;
  if (diff < 86400) return `${Math.floor(diff/3600)} jam lalu`;
  return `${Math.floor(diff/86400)} hari lalu`;
}

// ===== QUICK SUPPORT (HOMEPAGE PRESETS) =====
let quickSupportAmount = 10000;

window.selectQuickSupport = function(amount, el) {
  document.querySelectorAll('.quick-chip').forEach(c => c.classList.remove('active'));
  if (el) el.classList.add('active');
  quickSupportAmount = amount;
};

window.triggerQuickPayment = function() {
  selectedGift = quickSupportAmount;
  selectedPayment = 'QRIS';
  
  const fee = Math.round(selectedGift * 0.01);
  const total = selectedGift + fee;
  
  showPaymentModal(selectedGift, fee, total, selectedPayment);
};

// ===== INIT =====
document.addEventListener('DOMContentLoaded', () => {
  checkSession();
  renderTopCreators();
  renderCreators('Semua');
  loadCreatorProfile();
  loadDashboard();
});
