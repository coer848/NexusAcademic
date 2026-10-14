/**
 * NexusAcademic Enterprise - Client Controller & SPA Engine
 * Glassmorphic UI, Audio Synthesizer, Dynamic QR Live Engine, WebRTC Scanner & Gradebook
 */

// ================= GLOBAL STATE =================
const AppState = {
  token: localStorage.getItem('nexus_token') || null,
  user: null,
  institution: null,
  currentRole: null, // 'ADMIN', 'TEACHER', 'STUDENT'
  teacherGroups: [],
  selectedGroupId: null,
  activeSession: null,
  qrRotationInterval: null,
  qrCountdownTimer: null,
  qrSecondsRemaining: 20,
  attendancePollInterval: null,

  // Camera & Video Stream State
  cameraStream: null,
  isCameraRunning: false,
  scanAnimFrameId: null,
  isScanCooldown: false,

  // Geolocation
  userCoordinates: { lat: 4.60971, lng: -74.08175 }
};

// ================= AUDIO SYNTHESIZER (WEB AUDIO API) =================
class AudioEngine {
  constructor() {
    this.ctx = null;
  }

  init() {
    if (!this.ctx) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      this.ctx = new AudioCtx();
    }
  }

  playSuccess() {
    try {
      this.init();
      if (this.ctx.state === 'suspended') this.ctx.resume();

      const osc1 = this.ctx.createOscillator();
      const osc2 = this.ctx.createOscillator();
      const gain = this.ctx.createGain();

      osc1.type = 'sine';
      osc2.type = 'triangle';

      osc1.frequency.setValueAtTime(880, this.ctx.currentTime); // A5
      osc1.frequency.exponentialRampToValueAtTime(1318.5, this.ctx.currentTime + 0.12); // E6

      osc2.frequency.setValueAtTime(440, this.ctx.currentTime);
      osc2.frequency.exponentialRampToValueAtTime(659.25, this.ctx.currentTime + 0.12);

      gain.gain.setValueAtTime(0.15, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + 0.35);

      osc1.connect(gain);
      osc2.connect(gain);
      gain.connect(this.ctx.destination);

      osc1.start();
      osc2.start();
      osc1.stop(this.ctx.currentTime + 0.35);
      osc2.stop(this.ctx.currentTime + 0.35);
    } catch (e) { }
  }

  playError() {
    try {
      this.init();
      if (this.ctx.state === 'suspended') this.ctx.resume();

      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();

      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(330, this.ctx.currentTime);
      osc.frequency.setValueAtTime(220, this.ctx.currentTime + 0.18);

      gain.gain.setValueAtTime(0.12, this.ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + 0.38);

      osc.connect(gain);
      gain.connect(this.ctx.destination);

      osc.start();
      osc.stop(this.ctx.currentTime + 0.38);
    } catch (e) { }
  }
}

const sfx = new AudioEngine();

// ================= TOAST NOTIFICATIONS =================
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  let icon = 'fa-circle-check';
  if (type === 'error') icon = 'fa-circle-xmark';
  if (type === 'info') icon = 'fa-circle-info';
  if (type === 'warning') icon = 'fa-triangle-exclamation';

  toast.innerHTML = `
    <i class="fa-solid ${icon}"></i>
    <span>${message}</span>
  `;

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

// ================= API BASE URL & CLIENT HELPER =================
// Automatically detects backend URL whether running on XAMPP (e.g. /NexusAcademic), localhost:3000, or custom domain
const API_BASE_URL = (() => {
  if (typeof window === 'undefined') return '';
  const { protocol, port, hostname, pathname } = window.location;
  if (protocol === 'file:') return 'http://localhost/NexusAcademic';

  // Detect if hosted under a subdirectory in XAMPP (e.g. /NexusAcademic/ or /NexusAcademic/public/)
  const segments = pathname.split('/').filter(Boolean);
  if (segments.length > 0 && segments[0].toLowerCase() === 'nexusacademic') {
    return '/NexusAcademic';
  }

  if (port === '3000') return '';
  return '';
})();

async function apiFetch(endpoint, options = {}) {
  let cleanEndpoint = endpoint;
  if (!cleanEndpoint.startsWith('http')) {
    if (!cleanEndpoint.startsWith('/')) cleanEndpoint = '/' + cleanEndpoint;
    cleanEndpoint = `${API_BASE_URL}${cleanEndpoint}`;
  }
  const url = cleanEndpoint;
  const headers = options.headers || {};
  if (AppState.token) {
    headers['Authorization'] = `Bearer ${AppState.token}`;
  }
  if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }

  options.headers = headers;

  try {
    const res = await fetch(url, options);

    // Safely parse body text to avoid "Unexpected end of JSON input" on empty/HTML responses
    const text = await res.text();
    let data = null;
    if (text && text.trim().length > 0) {
      try {
        data = JSON.parse(text);
      } catch (parseErr) {
        // Fallback for non-JSON responses (HTML 404/500 from static servers/proxies)
        data = { message: text.slice(0, 160) };
      }
    }

    if (!res.ok) {
      const errorMsg = (data && (data.message || data.error))
        || (res.status === 404
          ? `Ruta no encontrada (${endpoint}). Verifica que el servidor backend esté corriendo.`
          : `Error en la petición (${res.status} ${res.statusText || 'Error del servidor'})`);
      throw new Error(errorMsg);
    }

    return data || {};
  } catch (err) {
    if (err.name === 'TypeError' || (err.message && (err.message.includes('Failed to fetch') || err.message.includes('NetworkError')))) {
      throw new Error('No se pudo conectar con el servidor backend. Asegúrate de ejecutar "npm start" (puerto 3000) en la terminal.');
    }
    throw err;
  }
}

// ================= LIVE CLOCK =================
function initLiveClock() {
  const timeDisplay = document.getElementById('timeDisplay');
  function update() {
    const now = new Date();
    timeDisplay.textContent = now.toLocaleTimeString();
  }
  setInterval(update, 1000);
  update();
}

// ================= GEOLOCATION CAPTURE =================
function initGeolocation() {
  const geoText = document.getElementById('geoStatusText');
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        AppState.userCoordinates = {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude
        };
        if (geoText) geoText.textContent = `Lat: ${pos.coords.latitude.toFixed(4)}, Lng: ${pos.coords.longitude.toFixed(4)}`;
      },
      (err) => {
        if (geoText) geoText.textContent = `Ubicación estándar de campus (Lat: 4.6097, Lng: -74.0817)`;
      },
      { timeout: 8000 }
    );
  } else {
    if (geoText) geoText.textContent = `Ubicación estándar de campus`;
  }
}

// ================= VIEW & ROLE NAVIGATION =================
function showView(viewId) {
  document.querySelectorAll('.view-section').forEach(sec => {
    sec.classList.remove('active');
    sec.classList.add('hidden');
  });

  const target = document.getElementById(viewId);
  if (target) {
    target.classList.remove('hidden');
    target.classList.add('active');
  }

  // Highlight active nav button
  document.querySelectorAll('#mainNavLinks .nav-btn').forEach(btn => {
    const attr = btn.getAttribute('onclick') || '';
    if (attr.includes(`'${viewId}'`)) {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });

  if (viewId === 'viewProfile') {
    loadUserProfile();
  } else if (viewId === 'viewMasterDev') {
    loadMasterDevDashboard();
  }
}

function updateNavBar(role) {
  const linksContainer = document.getElementById('mainNavLinks');
  const userPill = document.getElementById('userProfileBadge');
  const userAvatar = document.getElementById('userAvatarText');
  const userName = document.getElementById('userPillName');
  const userRole = document.getElementById('userPillRole');

  if (!AppState.user) {
    linksContainer.innerHTML = '';
    userPill.classList.add('hidden');
    return;
  }

  userPill.classList.remove('hidden');
  const initials = `${AppState.user.firstName ? AppState.user.firstName[0] : 'U'}${AppState.user.lastName ? AppState.user.lastName[0] : ''}`;
  userAvatar.textContent = initials;
  userName.textContent = `${AppState.user.firstName} ${AppState.user.lastName}`;
  userRole.textContent = AppState.user.role === 'SUPERADMIN' ? 'MASTER DEV' : AppState.user.role;

  // Make user badge pill navigate to profile
  userPill.onclick = (e) => {
    if (e.target.closest('#btnLogout')) return;
    showView('viewProfile');
  };

  const profileBtnHtml = `
    <button class="nav-btn" onclick="showView('viewProfile')" id="navBtnProfile" title="Ver mi información y perfil">
      <i class="fa-solid fa-id-card"></i> Mi Perfil
    </button>
  `;

  if (role === 'SUPERADMIN') {
    linksContainer.innerHTML = `
      <button class="nav-btn active" onclick="showView('viewMasterDev')" title="Consola del Programador">
        <i class="fa-solid fa-terminal"></i> Consola Master
      </button>
      <button class="nav-btn" onclick="showView('viewAdmin')" title="Gestión Institucional">
        <i class="fa-solid fa-crown"></i> Panel Admin
      </button>
      ${profileBtnHtml}
    `;
  } else if (role === 'ADMIN') {
    linksContainer.innerHTML = `
      <button class="nav-btn active" onclick="showView('viewAdmin')">
        <i class="fa-solid fa-crown"></i> Panel Admin
      </button>
      ${profileBtnHtml}
    `;
  } else if (role === 'TEACHER') {
    linksContainer.innerHTML = `
      <button class="nav-btn active" onclick="showView('viewTeacher')">
        <i class="fa-solid fa-chalkboard-user"></i> Mis Clases & Asistencia
      </button>
      ${profileBtnHtml}
    `;
  } else if (role === 'STUDENT') {
    linksContainer.innerHTML = `
      <button class="nav-btn active" onclick="showView('viewStudent')">
        <i class="fa-solid fa-graduation-cap"></i> Mi Portal Estudiantil
      </button>
      ${profileBtnHtml}
    `;
  }
}

// ================= AUTHENTICATION FLOW =================
async function checkAuthSession() {
  if (!AppState.token) {
    showView('viewLogin');
    updateNavBar(null);
    return;
  }

  try {
    const data = await apiFetch('/api/auth/me');
    AppState.user = data.user;
    AppState.institution = data.institution;
    AppState.currentRole = data.user.role;

    updateNavBar(AppState.user.role);

    if (AppState.user.role === 'SUPERADMIN') {
      loadMasterDevDashboard();
      showView('viewMasterDev');
    } else if (AppState.user.role === 'ADMIN') {
      loadAdminDashboard();
      showView('viewAdmin');
    } else if (AppState.user.role === 'TEACHER') {
      loadTeacherDashboard();
      showView('viewTeacher');
    } else if (AppState.user.role === 'STUDENT') {
      loadStudentDashboard();
      showView('viewStudent');
    }
  } catch (err) {
    console.warn('Session expired or invalid:', err);
    logout();
  }
}

async function login(email, password) {
  try {
    const data = await apiFetch('/api/auth/login', {
      method: 'POST',
      body: { email, password }
    });

    AppState.token = data.token;
    localStorage.setItem('nexus_token', data.token);
    AppState.user = data.user;
    AppState.currentRole = data.user.role;

    sfx.playSuccess();
    showToast(`¡Bienvenido/a, ${data.user.firstName}!`, 'success');

    updateNavBar(data.user.role);

    if (data.user.role === 'SUPERADMIN') {
      loadMasterDevDashboard();
      showView('viewMasterDev');
    } else if (data.user.role === 'ADMIN') {
      loadAdminDashboard();
      showView('viewAdmin');
    } else if (data.user.role === 'TEACHER') {
      loadTeacherDashboard();
      showView('viewTeacher');
    } else if (data.user.role === 'STUDENT') {
      loadStudentDashboard();
      showView('viewStudent');
    }
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

async function handleRegisterSubmit(e) {
  e.preventDefault();
  const firstName = document.getElementById('regFirstName').value.trim();
  const lastName = document.getElementById('regLastName').value.trim();
  const phone = document.getElementById('regPhone') ? document.getElementById('regPhone').value.trim() : '';
  const email = document.getElementById('regEmail').value.trim();
  const password = document.getElementById('regPassword').value;
  const role = document.getElementById('regRole').value;
  const code = document.getElementById('regCode').value.trim();
  const career = document.getElementById('regCareer').value.trim();
  const department = document.getElementById('regDepartment').value.trim();
  const institutionalKey = document.getElementById('regInstitutionalKey') ? document.getElementById('regInstitutionalKey').value.trim() : '';

  if (role === 'TEACHER' && !institutionalKey) {
    sfx.playError();
    showToast('El registro de Docentes requiere la Clave de Autorización Institucional.', 'error');
    const inputKey = document.getElementById('regInstitutionalKey');
    if (inputKey) inputKey.focus();
    return;
  }

  try {
    const data = await apiFetch('/api/auth/register', {
      method: 'POST',
      body: {
        first_name: firstName,
        last_name: lastName,
        phone: phone,
        email: email,
        password: password,
        role: role,
        code: code || undefined,
        career: role === 'STUDENT' ? career : undefined,
        department: role === 'TEACHER' ? department : undefined,
        institutional_key: role === 'TEACHER' ? institutionalKey : undefined
      }
    });

    AppState.token = data.token;
    localStorage.setItem('nexus_token', data.token);
    AppState.user = data.user;
    AppState.currentRole = data.user.role;

    sfx.playSuccess();
    showToast(`¡Registro exitoso! Bienvenido/a, ${data.user.firstName}`, 'success');

    updateNavBar(data.user.role);

    if (data.user.role === 'ADMIN') {
      loadAdminDashboard();
      showView('viewAdmin');
    } else if (data.user.role === 'TEACHER') {
      loadTeacherDashboard();
      showView('viewTeacher');
    } else if (data.user.role === 'STUDENT') {
      loadStudentDashboard();
      showView('viewStudent');
    }
  } catch (err) {
    sfx.playError();
    showToast(`Error al registrarse: ${err.message}`, 'error');
  }
}

function logout() {
  localStorage.removeItem('nexus_token');
  AppState.token = null;
  AppState.user = null;
  AppState.currentRole = null;

  clearInterval(AppState.qrRotationInterval);
  clearInterval(AppState.qrCountdownTimer);
  clearInterval(AppState.attendancePollInterval);

  stopCameraStream();

  showToast('Sesión finalizada.', 'info');
  updateNavBar(null);
  showView('viewLogin');
}

// ================= USER PROFILE & ACCOUNT MANAGEMENT CONTROLLER =================
async function loadUserProfile() {
  if (!AppState.user) {
    try {
      const data = await apiFetch('/api/auth/me');
      AppState.user = data.user;
    } catch (e) {
      console.error('Error al cargar datos del usuario:', e);
      return;
    }
  }

  const u = AppState.user;
  const initials = `${u.firstName ? u.firstName[0] : 'U'}${u.lastName ? u.lastName[0] : ''}`;

  // 1. Overview Card
  const avatarBig = document.getElementById('profileAvatarBig');
  if (avatarBig) avatarBig.textContent = initials;

  const displayName = document.getElementById('profileDisplayName');
  if (displayName) displayName.textContent = `${u.firstName} ${u.lastName}`;

  const displayEmail = document.getElementById('profileDisplayEmail');
  if (displayEmail) displayEmail.textContent = u.email;

  const roleTag = document.getElementById('profileRoleTag');
  if (roleTag) {
    roleTag.textContent = u.role === 'ADMIN' ? 'ADMINISTRADOR' : (u.role === 'TEACHER' ? 'DOCENTE' : 'ESTUDIANTE');
  }

  const badgeRole = document.getElementById('profileBadgeRole');
  if (badgeRole) {
    badgeRole.className = `badge-role ${u.role ? u.role.toLowerCase() : 'student'}`;
  }

  const displayPhone = document.getElementById('profileDisplayPhone');
  if (displayPhone) displayPhone.textContent = u.phone ? u.phone : 'No registrado';

  const displayId = document.getElementById('profileDisplayId');
  if (displayId) displayId.textContent = `#${u.id}`;

  const displayStatus = document.getElementById('profileDisplayStatus');
  if (displayStatus) displayStatus.textContent = u.status || 'ACTIVO';

  const displayCreatedAt = document.getElementById('profileDisplayCreatedAt');
  if (displayCreatedAt) {
    if (u.createdAt) {
      try {
        const d = new Date(u.createdAt);
        displayCreatedAt.textContent = d.toLocaleDateString('es-ES', { year: 'numeric', month: 'long', day: 'numeric' });
      } catch (err) {
        displayCreatedAt.textContent = u.createdAt;
      }
    } else {
      displayCreatedAt.textContent = 'Registrado';
    }
  }

  // 2. Role-specific Details Card
  const roleTitle = document.getElementById('profileRoleDetailsTitle');
  const roleContent = document.getElementById('profileRoleDetailsContent');
  const studentExtra = document.getElementById('profileStudentExtraFields');
  const teacherExtra = document.getElementById('profileTeacherExtraFields');

  if (u.role === 'STUDENT') {
    if (roleTitle) roleTitle.innerHTML = '<i class="fa-solid fa-graduation-cap"></i> Información Académica';
    if (roleContent) {
      roleContent.innerHTML = `
        <div class="profile-role-item">
          <span>Código Estudiantil:</span>
          <strong>${u.student?.student_code || 'N/A'}</strong>
        </div>
        <div class="profile-role-item">
          <span>Carrera Universitaria:</span>
          <strong>${u.student?.career || 'N/A'}</strong>
        </div>
        <div class="profile-role-item">
          <span>Semestre Actual:</span>
          <strong>${u.student?.semester ? `${u.student.semester}° Semestre` : '1° Semestre'}</strong>
        </div>
      `;
    }
    if (studentExtra) studentExtra.classList.remove('hidden');
    if (teacherExtra) teacherExtra.classList.add('hidden');

    const inpCareer = document.getElementById('inputProfileCareer');
    if (inpCareer) inpCareer.value = u.student?.career || '';
    const inpSemester = document.getElementById('inputProfileSemester');
    if (inpSemester) inpSemester.value = u.student?.semester || 1;

  } else if (u.role === 'TEACHER') {
    if (roleTitle) roleTitle.innerHTML = '<i class="fa-solid fa-chalkboard-user"></i> Datos del Docente';
    if (roleContent) {
      roleContent.innerHTML = `
        <div class="profile-role-item">
          <span>Código de Docente:</span>
          <strong>${u.teacher?.teacher_code || 'N/A'}</strong>
        </div>
        <div class="profile-role-item">
          <span>Departamento:</span>
          <strong>${u.teacher?.department || 'N/A'}</strong>
        </div>
        <div class="profile-role-item">
          <span>Especialidad:</span>
          <strong>${u.teacher?.specialty || 'General'}</strong>
        </div>
      `;
    }
    if (studentExtra) studentExtra.classList.add('hidden');
    if (teacherExtra) teacherExtra.classList.remove('hidden');

    const inpDept = document.getElementById('inputProfileDept');
    if (inpDept) inpDept.value = u.teacher?.department || '';
    const inpSpec = document.getElementById('inputProfileSpecialty');
    if (inpSpec) inpSpec.value = u.teacher?.specialty || '';

  } else if (u.role === 'ADMIN') {
    if (roleTitle) roleTitle.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Privilegios de Sistema';
    if (roleContent) {
      roleContent.innerHTML = `
        <div class="profile-role-item">
          <span>Nivel de Acceso:</span>
          <strong>Superadministrador</strong>
        </div>
        <div class="profile-role-item">
          <span>Control Criptográfico:</span>
          <strong>Activo & Habilitado</strong>
        </div>
        <div class="profile-role-item">
          <span>Auditoría de Acciones:</span>
          <strong>Inmutable en Base de Datos</strong>
        </div>
      `;
    }
    if (studentExtra) studentExtra.classList.add('hidden');
    if (teacherExtra) teacherExtra.classList.add('hidden');
  }

  // 3. Populate Editable Form Fields
  const inpFirst = document.getElementById('inputProfileFirstName');
  if (inpFirst) inpFirst.value = u.firstName || '';
  const inpLast = document.getElementById('inputProfileLastName');
  if (inpLast) inpLast.value = u.lastName || '';
  const inpEmail = document.getElementById('inputProfileEmail');
  if (inpEmail) inpEmail.value = u.email || '';
  const inpPhone = document.getElementById('inputProfilePhone');
  if (inpPhone) inpPhone.value = u.phone || '';
}

async function handleProfileUpdateSubmit(e) {
  e.preventDefault();
  const firstName = document.getElementById('inputProfileFirstName').value.trim();
  const lastName = document.getElementById('inputProfileLastName').value.trim();
  const phone = document.getElementById('inputProfilePhone').value.trim();

  const body = { firstName, lastName, phone };

  if (AppState.user?.role === 'STUDENT') {
    const career = document.getElementById('inputProfileCareer').value.trim();
    const semester = parseInt(document.getElementById('inputProfileSemester').value) || 1;
    body.career = career;
    body.semester = semester;
  } else if (AppState.user?.role === 'TEACHER') {
    const department = document.getElementById('inputProfileDept').value.trim();
    const specialty = document.getElementById('inputProfileSpecialty').value.trim();
    body.department = department;
    body.specialty = specialty;
  }

  const btn = document.getElementById('btnSaveProfile');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
  }

  try {
    const res = await apiFetch('/api/auth/profile', {
      method: 'PUT',
      body: body
    });

    AppState.user = res.user;
    sfx.playSuccess();
    showToast('¡Perfil y datos actualizados correctamente!', 'success');
    updateNavBar(AppState.user.role);
    loadUserProfile();
  } catch (err) {
    sfx.playError();
    showToast(`Error al actualizar perfil: ${err.message}`, 'error');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar Cambios en mi Perfil';
    }
  }
}

async function handleProfileChangePasswordSubmit(e) {
  e.preventDefault();
  const currentPassword = document.getElementById('inputProfileCurrentPass').value;
  const newPassword = document.getElementById('inputProfileNewPass').value;

  if (newPassword.length < 6) {
    showToast('La nueva contraseña debe tener al menos 6 caracteres.', 'warning');
    return;
  }

  const btn = document.getElementById('btnUpdatePassword');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Actualizando...';
  }

  try {
    const res = await apiFetch('/api/auth/change-password', {
      method: 'POST',
      body: { currentPassword, newPassword }
    });

    sfx.playSuccess();
    showToast(res.message || '¡Contraseña actualizada con éxito!', 'success');
    document.getElementById('formProfileChangePassword').reset();
  } catch (err) {
    sfx.playError();
    showToast(`Error al cambiar contraseña: ${err.message}`, 'error');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Actualizar Contraseña';
    }
  }
}

function openDeleteAccountModal() {
  const modal = document.getElementById('modalDeleteAccount');
  if (!modal) return;
  const inpPass = document.getElementById('inputDeletePassword');
  if (inpPass) inpPass.value = '';
  const chk = document.getElementById('checkConfirmDelete');
  if (chk) chk.checked = false;
  modal.classList.remove('hidden');
}

function closeDeleteAccountModal() {
  const modal = document.getElementById('modalDeleteAccount');
  if (modal) modal.classList.add('hidden');
}

async function handleDeleteAccountSubmit(e) {
  e.preventDefault();
  const password = document.getElementById('inputDeletePassword').value;
  const isChecked = document.getElementById('checkConfirmDelete').checked;

  if (!isChecked) {
    showToast('Por favor marca la casilla confirmando que entiendes la acción.', 'warning');
    return;
  }

  if (!password) {
    showToast('Debes ingresar tu contraseña para confirmar la eliminación.', 'warning');
    return;
  }

  const btnConfirm = document.getElementById('btnConfirmDelete');
  if (btnConfirm) {
    btnConfirm.disabled = true;
    btnConfirm.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Eliminando tu cuenta...';
  }

  try {
    const res = await apiFetch('/api/auth/delete-account', {
      method: 'POST',
      body: { password }
    });

    closeDeleteAccountModal();
    sfx.playSuccess();
    showToast(res.message || 'Tu cuenta ha sido eliminada definitivamente.', 'info');

    setTimeout(() => {
      logout();
    }, 1200);
  } catch (err) {
    sfx.playError();
    showToast(`Error al eliminar la cuenta: ${err.message}`, 'error');
    if (btnConfirm) {
      btnConfirm.disabled = false;
      btnConfirm.innerHTML = '<i class="fa-solid fa-trash-can"></i> Sí, Eliminar mi Cuenta';
    }
  }
}


// ================= ADMIN DASHBOARD CONTROLLER =================
async function loadAdminDashboard() {
  try {
    // 1. Metrics
    const metrics = await apiFetch('/api/audit/metrics');
    document.getElementById('statAdminStudents').textContent = metrics.totalStudents;
    document.getElementById('statAdminTeachers').textContent = metrics.totalTeachers;
    document.getElementById('statAdminAttendances').textContent = metrics.totalAttendances;
    document.getElementById('statAdminGrades').textContent = metrics.totalGrades;
    document.getElementById('statAdminGradeChanges').textContent = `${metrics.totalGradeChanges} cambios auditados`;

    // 2. Groups Table
    const groups = await apiFetch('/api/academic/groups');
    const tbodyGroups = document.querySelector('#tableAdminGroups tbody');
    tbodyGroups.innerHTML = groups.map(g => `
      <tr>
        <td><strong>${g.group_code}</strong></td>
        <td>${g.subject_name} (${g.subject_code})</td>
        <td><strong>Prof. ${g.teacher_first_name} ${g.teacher_last_name}</strong></td>
        <td>${g.schedule} • <span class="info-chip">${g.classroom}</span></td>
        <td><span class="badge-pill">${g.enrolled_students_count} alumnos</span></td>
        <td><span class="badge-pill">${g.total_sessions_count} sesiones</span></td>
        <td>
          <div style="display:flex; gap:0.4rem;">
            <button class="btn btn-sm btn-glass" onclick="openEditGroupModal(${g.id})" title="Editar Asignación / Horario">
              <i class="fa-solid fa-pen-to-square"></i>
            </button>
            <button class="btn btn-sm btn-glass text-danger" onclick="deleteGroupRecord(${g.id})" title="Eliminar Grupo">
              <i class="fa-solid fa-trash"></i>
            </button>
          </div>
        </td>
      </tr>
    `).join('');

    // 3. Teachers Table (with Edit profile button)
    const teachers = await apiFetch('/api/academic/teachers');
    const tbodyTeachers = document.querySelector('#tableAdminTeachers tbody');
    tbodyTeachers.innerHTML = teachers.map(t => `
      <tr>
        <td><code>${t.teacher_code}</code></td>
        <td><strong>${t.first_name} ${t.last_name}</strong></td>
        <td>${t.email}</td>
        <td>${t.department || 'Sin dpto.'}</td>
        <td>${t.specialty || 'General'}</td>
        <td><span class="badge-pill">${t.assigned_groups_count || 0} cursos</span></td>
        <td>
          <button class="btn btn-sm btn-primary" onclick="openEditTeacherModal(${t.id}, '${t.first_name}', '${t.last_name}', '${t.department || ''}', '${t.specialty || ''}')" title="Editar Parámetros del Docente">
            <i class="fa-solid fa-pen"></i> Editar
          </button>
        </td>
      </tr>
    `).join('');

    // 4. Subjects Table
    const subjects = await apiFetch('/api/academic/subjects');
    const tbodySubs = document.querySelector('#tableAdminSubjects tbody');
    tbodySubs.innerHTML = subjects.map(s => `
      <tr>
        <td><code>${s.code}</code></td>
        <td><strong>${s.name}</strong></td>
        <td>${s.credits} Créditos</td>
        <td>${s.description || 'Sin descripción'}</td>
      </tr>
    `).join('');

    // 5. Students Table
    const students = await apiFetch('/api/academic/students');
    const tbodyUsers = document.querySelector('#tableAdminUsers tbody');
    tbodyUsers.innerHTML = students.map(s => `
      <tr>
        <td><code>${s.student_code}</code></td>
        <td><strong>${s.first_name} ${s.last_name}</strong></td>
        <td>${s.email}</td>
        <td>${s.career || 'Ingeniería'}</td>
        <td>Semestre ${s.semester || 1}</td>
        <td><span class="badge-pill">${s.enrolled_courses_count || 0} asignaturas</span></td>
      </tr>
    `).join('');

    // 6. Audit Logs
    const auditLogs = await apiFetch('/api/audit/logs?limit=50');
    const tbodyAudit = document.querySelector('#tableAdminAudit tbody');
    tbodyAudit.innerHTML = auditLogs.map(l => `
      <tr>
        <td><code>${new Date(l.created_at).toLocaleString()}</code></td>
        <td>${l.first_name ? `${l.first_name} ${l.last_name}` : 'Sistema'}</td>
        <td><span class="badge-role admin">${l.action}</span></td>
        <td>${l.resource_type} #${l.resource_id || ''}</td>
        <td><code>${l.details_json || '{}'}</code></td>
        <td>${l.ip_address || '-'}</td>
      </tr>
    `).join('');

    // 7. Global Grade History
    const gradeHistory = await apiFetch('/api/audit/grades-history');
    const tbodyHist = document.querySelector('#tableAdminGradeHistory tbody');
    tbodyHist.innerHTML = gradeHistory.map(h => `
      <tr>
        <td><code>${new Date(h.created_at).toLocaleString()}</code></td>
        <td>${h.student_first_name} ${h.student_last_name} (<code>${h.student_code}</code>)</td>
        <td>${h.subject_name} • ${h.evaluation_name} (${h.evaluation_weight}%)</td>
        <td>${h.old_score != null ? `<span class="badge-pill">${h.old_score}</span>` : '<em>Inicial</em>'}</td>
        <td><strong class="eval-score-num">${h.new_score}</strong></td>
        <td>${h.changed_by_first_name} ${h.changed_by_last_name}</td>
        <td>${h.change_reason}</td>
      </tr>
    `).join('');

  } catch (err) {
    showToast(`Error al cargar datos administrativos: ${err.message}`, 'error');
  }
}

// Admin Modal Actions
window.openCreateGroupModal = async function () {
  document.getElementById('modalGroupTitle').textContent = 'Crear Nuevo Grupo / Clase';
  document.getElementById('groupEditId').value = '';
  document.getElementById('inputGroupCode').value = '';
  document.getElementById('inputGroupSchedule').value = 'Lun y Mié 08:00 - 10:00';
  document.getElementById('inputGroupClassroom').value = 'Aula 201';

  // Load dropdown options
  await populateGroupModalDropdowns();
  document.getElementById('modalGroup').classList.remove('hidden');
};

window.openEditGroupModal = async function (groupId) {
  try {
    document.getElementById('modalGroupTitle').textContent = 'Editar Asignación de Grupo';
    document.getElementById('groupEditId').value = groupId;

    await populateGroupModalDropdowns();

    const group = await apiFetch(`/api/academic/groups/${groupId}`);
    const g = group.group;

    document.getElementById('selectGroupSubject').value = g.subject_id;
    document.getElementById('selectGroupTeacher').value = g.teacher_id;
    document.getElementById('inputGroupCode').value = g.group_code;
    document.getElementById('selectGroupPeriod').value = g.period_id;
    document.getElementById('inputGroupSchedule').value = g.schedule;
    document.getElementById('inputGroupClassroom').value = g.classroom;

    document.getElementById('modalGroup').classList.remove('hidden');
  } catch (err) {
    showToast(`Error al cargar grupo: ${err.message}`, 'error');
  }
};

async function populateGroupModalDropdowns() {
  const [subjects, teachers, periods] = await Promise.all([
    apiFetch('/api/academic/subjects'),
    apiFetch('/api/academic/teachers'),
    apiFetch('/api/academic/periods')
  ]);

  const selectSub = document.getElementById('selectGroupSubject');
  selectSub.innerHTML = subjects.map(s => `<option value="${s.id}">${s.code} - ${s.name}</option>`).join('');

  const selectTeach = document.getElementById('selectGroupTeacher');
  selectTeach.innerHTML = teachers.map(t => `<option value="${t.id}">Prof. ${t.first_name} ${t.last_name} (${t.department || 'Docente'})</option>`).join('');

  const selectPer = document.getElementById('selectGroupPeriod');
  selectPer.innerHTML = periods.map(p => `<option value="${p.id}">${p.name} (${p.code})</option>`).join('');
}

async function submitGroupForm(e) {
  e.preventDefault();
  const editId = document.getElementById('groupEditId').value;
  const payload = {
    subject_id: Number(document.getElementById('selectGroupSubject').value),
    teacher_id: Number(document.getElementById('selectGroupTeacher').value),
    group_code: document.getElementById('inputGroupCode').value.trim(),
    period_id: Number(document.getElementById('selectGroupPeriod').value),
    schedule: document.getElementById('inputGroupSchedule').value.trim(),
    classroom: document.getElementById('inputGroupClassroom').value.trim()
  };

  try {
    if (editId) {
      await apiFetch(`/api/academic/groups/${editId}`, { method: 'PUT', body: payload });
      showToast('¡Asignación de clase y profesor actualizada!', 'success');
    } else {
      await apiFetch('/api/academic/groups', { method: 'POST', body: payload });
      showToast('¡Nuevo grupo creado y asignado al profesor!', 'success');
    }

    document.getElementById('modalGroup').classList.add('hidden');
    loadAdminDashboard();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

window.deleteGroupRecord = async function (groupId) {
  if (!confirm('¿Está seguro de eliminar este grupo y sus asignaciones?')) return;
  try {
    await apiFetch(`/api/academic/groups/${groupId}`, { method: 'DELETE' });
    showToast('Grupo eliminado correctamente.', 'info');
    loadAdminDashboard();
  } catch (err) {
    showToast(err.message, 'error');
  }
};

// Teacher Edit Modal Actions
window.openEditTeacherModal = function (id, firstName, lastName, dept, specialty) {
  document.getElementById('teacherEditId').value = id;
  document.getElementById('inputTeacherFirstName').value = firstName;
  document.getElementById('inputTeacherLastName').value = lastName;
  document.getElementById('inputTeacherDept').value = dept;
  document.getElementById('inputTeacherSpecialty').value = specialty;
  document.getElementById('modalTeacher').classList.remove('hidden');
};

async function submitTeacherForm(e) {
  e.preventDefault();
  const id = document.getElementById('teacherEditId').value;
  const payload = {
    first_name: document.getElementById('inputTeacherFirstName').value.trim(),
    last_name: document.getElementById('inputTeacherLastName').value.trim(),
    department: document.getElementById('inputTeacherDept').value.trim(),
    specialty: document.getElementById('inputTeacherSpecialty').value.trim()
  };

  try {
    await apiFetch(`/api/academic/teachers/${id}`, { method: 'PUT', body: payload });
    showToast('¡Perfil de docente actualizado con éxito!', 'success');
    document.getElementById('modalTeacher').classList.add('hidden');
    loadAdminDashboard();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// Enroll Student Modal Actions
window.openEnrollmentModal = async function () {
  try {
    const [students, groups] = await Promise.all([
      apiFetch('/api/academic/students'),
      apiFetch('/api/academic/groups')
    ]);

    const selectStud = document.getElementById('selectEnrollStudent');
    selectStud.innerHTML = students.map(s => `<option value="${s.id}">${s.student_code} - ${s.first_name} ${s.last_name}</option>`).join('');

    const selectGrp = document.getElementById('selectEnrollGroup');
    selectGrp.innerHTML = groups.map(g => `<option value="${g.id}">${g.group_code} • ${g.subject_name} (Prof. ${g.teacher_first_name} ${g.teacher_last_name})</option>`).join('');

    document.getElementById('modalEnrollment').classList.remove('hidden');
  } catch (err) {
    showToast(`Error al cargar datos: ${err.message}`, 'error');
  }
};

async function submitEnrollmentForm(e) {
  e.preventDefault();
  const student_id = Number(document.getElementById('selectEnrollStudent').value);
  const group_id = Number(document.getElementById('selectEnrollGroup').value);

  try {
    await apiFetch('/api/academic/enrollments', {
      method: 'POST',
      body: { student_id, group_id }
    });
    showToast('¡Estudiante matriculado con éxito!', 'success');
    document.getElementById('modalEnrollment').classList.add('hidden');
    loadAdminDashboard();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// New Subject Modal
window.openSubjectModal = function () {
  document.getElementById('inputSubjectCode').value = '';
  document.getElementById('inputSubjectName').value = '';
  document.getElementById('inputSubjectCredits').value = 3;
  document.getElementById('inputSubjectDesc').value = '';
  document.getElementById('modalSubject').classList.remove('hidden');
};

async function submitSubjectForm(e) {
  e.preventDefault();
  const payload = {
    code: document.getElementById('inputSubjectCode').value.trim(),
    name: document.getElementById('inputSubjectName').value.trim(),
    credits: Number(document.getElementById('inputSubjectCredits').value),
    description: document.getElementById('inputSubjectDesc').value.trim()
  };

  try {
    await apiFetch('/api/academic/subjects', { method: 'POST', body: payload });
    showToast('¡Asignatura creada con éxito!', 'success');
    document.getElementById('modalSubject').classList.add('hidden');
    loadAdminDashboard();
  } catch (err) {
    showToast(err.message, 'error');
  }
}

// ================= TEACHER DASHBOARD & COURSE DEFINITION CONTROLLER =================
async function loadTeacherDashboard() {
  try {
    document.getElementById('teacherGreeting').textContent = `Mis Asignaturas y Clases — Prof. ${AppState.user.firstName} ${AppState.user.lastName}`;

    const groups = await apiFetch('/api/academic/groups');
    AppState.teacherGroups = groups;

    const select = document.getElementById('selectTeacherGroup');
    const btnEditCurrent = document.getElementById('btnTeacherEditCurrentCourse');
    const btnDelCurrent = document.getElementById('btnTeacherDeleteCurrentCourse');

    if (groups.length === 0) {
      select.innerHTML = '<option value="">No tienes asignaturas registradas. ¡Crea una!</option>';
      document.getElementById('groupQuickInfo').innerHTML = `
        <span class="info-chip"><i class="fa-solid fa-circle-info"></i> Puedes crear tu propia asignatura pulsando "Crear Mi Asignatura / Clase"</span>
      `;
      if (btnEditCurrent) btnEditCurrent.classList.add('hidden');
      if (btnDelCurrent) btnDelCurrent.classList.add('hidden');
      await refreshGroupSessionAndQR(null);
      return;
    }

    if (btnEditCurrent) btnEditCurrent.classList.remove('hidden');
    if (btnDelCurrent) btnDelCurrent.classList.remove('hidden');

    select.innerHTML = groups.map(g => `
      <option value="${g.id}">${g.subject_name} (${g.group_code}) — ${g.classroom}</option>
    `).join('');

    // Restore or pick selected group
    if (!AppState.selectedGroupId || !groups.some(g => g.id === AppState.selectedGroupId)) {
      AppState.selectedGroupId = groups[0].id;
    }
    select.value = AppState.selectedGroupId;
    await onTeacherGroupSelected(AppState.selectedGroupId);

  } catch (err) {
    showToast(`Error al cargar panel docente: ${err.message}`, 'error');
  }
}

async function onTeacherGroupSelected(groupId) {
  if (!groupId) return;
  AppState.selectedGroupId = Number(groupId);
  const group = AppState.teacherGroups.find(g => g.id === AppState.selectedGroupId);

  if (group) {
    document.getElementById('groupQuickInfo').innerHTML = `
      <span class="info-chip"><i class="fa-regular fa-clock"></i> ${group.schedule}</span>
      <span class="info-chip"><i class="fa-solid fa-location-dot"></i> ${group.classroom}</span>
      <span class="info-chip"><i class="fa-solid fa-users"></i> ${group.enrolled_students_count} Alumnos</span>
    `;
  }

  // Load group details, active session/QR, evaluations, gradebook matrix and history
  await refreshGroupSessionAndQR(AppState.selectedGroupId);
  await loadTeacherEvaluations(AppState.selectedGroupId);
  await loadTeacherGradebookMatrix(AppState.selectedGroupId);
  await loadTeacherGradeHistory();
}

// Teacher Course Management (Teacher writes their own subject directly)
window.openTeacherCreateCourseModal = function () {
  document.getElementById('modalTeacherCourseTitle').textContent = 'Crear Mi Asignatura a Impartir';
  document.getElementById('teacherCourseEditGroupId').value = '';
  document.getElementById('inputTeacherSubName').value = '';
  document.getElementById('inputTeacherSubCode').value = '';
  document.getElementById('inputTeacherGroupCode').value = 'G01';
  document.getElementById('inputTeacherSchedule').value = 'Lun y Mié 10:00 - 12:00';
  document.getElementById('inputTeacherClassroom').value = 'Aula 302';
  document.getElementById('inputTeacherDesc').value = '';
  document.getElementById('modalTeacherCourse').classList.remove('hidden');
};

window.openTeacherEditCourseModal = function () {
  if (!AppState.selectedGroupId) {
    showToast('Por favor seleccione una clase primero.', 'warning');
    return;
  }
  const group = AppState.teacherGroups.find(g => g.id === AppState.selectedGroupId);
  if (!group) return;

  document.getElementById('modalTeacherCourseTitle').textContent = 'Editar Asignatura y Parámetros';
  document.getElementById('teacherCourseEditGroupId').value = group.id;
  document.getElementById('inputTeacherSubName').value = group.subject_name;
  document.getElementById('inputTeacherSubCode').value = group.subject_code || '';
  document.getElementById('inputTeacherGroupCode').value = group.group_code;
  document.getElementById('inputTeacherSchedule').value = group.schedule;
  document.getElementById('inputTeacherClassroom').value = group.classroom;
  document.getElementById('inputTeacherDesc').value = '';
  document.getElementById('modalTeacherCourse').classList.remove('hidden');
};

window.teacherDeleteCurrentCourse = async function () {
  if (!AppState.selectedGroupId) {
    showToast('No hay una asignatura seleccionada.', 'warning');
    return;
  }
  const group = AppState.teacherGroups.find(g => g.id === AppState.selectedGroupId);
  const name = group ? group.subject_name : 'esta clase';

  if (!confirm(`¿Está seguro de eliminar la asignatura "${name}" (${group?.group_code}) y todas sus actividades asociadas?`)) return;

  try {
    await apiFetch(`/api/academic/groups/${AppState.selectedGroupId}`, { method: 'DELETE' });
    showToast('Asignatura y clase eliminadas correctamente.', 'info');
    AppState.selectedGroupId = null;
    await loadTeacherDashboard();
  } catch (err) {
    showToast(err.message, 'error');
  }
};

async function submitTeacherCourseForm(e) {
  e.preventDefault();
  const editId = document.getElementById('teacherCourseEditGroupId').value;
  const payload = {
    subject_name: document.getElementById('inputTeacherSubName').value.trim(),
    subject_code: document.getElementById('inputTeacherSubCode').value.trim() || undefined,
    group_code: document.getElementById('inputTeacherGroupCode').value.trim(),
    schedule: document.getElementById('inputTeacherSchedule').value.trim(),
    classroom: document.getElementById('inputTeacherClassroom').value.trim(),
    description: document.getElementById('inputTeacherDesc').value.trim()
  };

  try {
    if (editId) {
      await apiFetch(`/api/academic/groups/${editId}`, { method: 'PUT', body: payload });
      sfx.playSuccess();
      showToast('¡Asignatura y grupo actualizados exitosamente!', 'success');
    } else {
      const res = await apiFetch('/api/academic/teacher-course', { method: 'POST', body: payload });
      sfx.playSuccess();
      showToast(res.message || '¡Asignatura creada y asignada a su portal!', 'success');
      AppState.selectedGroupId = res.groupId;
    }

    document.getElementById('modalTeacherCourse').classList.add('hidden');
    await loadTeacherDashboard();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

async function refreshGroupSessionAndQR(groupId) {
  if (!groupId) {
    AppState.activeSession = null;
    const inactiveBox = document.getElementById('sessionInactiveControls');
    const activeStage = document.getElementById('activeQRStage');
    const badge = document.getElementById('sessionStatusBadge');
    const text = document.getElementById('sessionStatusText');
    if (inactiveBox) inactiveBox.classList.remove('hidden');
    if (activeStage) activeStage.classList.add('hidden');
    if (badge) badge.className = 'status-badge closed';
    if (text) text.textContent = 'SIN SESIÓN ACTIVA';
    return;
  }

  try {
    const details = await apiFetch(`/api/academic/groups/${groupId}`);
    const activeSession = details.activeSession;

    clearInterval(AppState.qrRotationInterval);
    clearInterval(AppState.qrCountdownTimer);
    clearInterval(AppState.attendancePollInterval);

    const inactiveBox = document.getElementById('sessionInactiveControls');
    const activeStage = document.getElementById('activeQRStage');
    const badge = document.getElementById('sessionStatusBadge');
    const text = document.getElementById('sessionStatusText');

    if (activeSession) {
      AppState.activeSession = activeSession;
      inactiveBox.classList.add('hidden');
      activeStage.classList.remove('hidden');
      badge.className = 'status-badge live pulse';
      text.textContent = 'EN VIVO • PROYECTANDO QR';

      document.getElementById('activeTopicLabel').textContent = activeSession.topic;

      // Start dynamic QR rotation loop
      await rotateTeacherQR();
      startQRCountdownLoop();

      // Poll attendance live roster every 3.5s
      pollLiveSessionAttendance(activeSession.id);
      AppState.attendancePollInterval = setInterval(() => {
        pollLiveSessionAttendance(activeSession.id);
      }, 3500);

    } else {
      AppState.activeSession = null;
      inactiveBox.classList.remove('hidden');
      activeStage.classList.add('hidden');
      badge.className = 'status-badge closed';
      text.textContent = 'SIN SESIÓN ACTIVA';

      document.getElementById('tbodySessionAttendance').innerHTML = `
        <tr><td colspan="5" class="empty-state-cell">No hay sesión activa para este grupo. Inicie una clase para proyectar el QR dinámico.</td></tr>
      `;
      document.getElementById('counterPresent').textContent = '0';
      document.getElementById('counterTotal').textContent = '0';
      document.getElementById('counterPercent').textContent = '0%';
    }
  } catch (err) {
    console.error('Error refreshing session:', err);
  }
}

async function startTeacherSession() {
  if (!AppState.selectedGroupId) {
    showToast('Por favor seleccione o cree una asignatura primero.', 'warning');
    return;
  }
  const topicInput = document.getElementById('inputSessionTopic');
  const topic = topicInput.value.trim() || 'Sesión de Clase';

  try {
    await apiFetch('/api/sessions/start', {
      method: 'POST',
      body: {
        group_id: AppState.selectedGroupId,
        topic: topic,
        geo_lat: AppState.userCoordinates.lat,
        geo_lng: AppState.userCoordinates.lng
      }
    });

    sfx.playSuccess();
    showToast('¡Sesión de clase iniciada! Código QR dinámico en pantalla.', 'success');
    await refreshGroupSessionAndQR(AppState.selectedGroupId);
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

async function closeTeacherSession() {
  if (!AppState.activeSession) return;
  try {
    await apiFetch(`/api/sessions/${AppState.activeSession.id}/close`, { method: 'POST' });
    sfx.playSuccess();
    showToast('Sesión de clase finalizada y cerrada.', 'info');
    await refreshGroupSessionAndQR(AppState.selectedGroupId);
  } catch (err) {
    showToast(err.message, 'error');
  }
}

async function rotateTeacherQR() {
  if (!AppState.activeSession) return;
  try {
    const data = await apiFetch(`/api/sessions/${AppState.activeSession.id}/qr-token`);
    const payload = data.qrPayload;

    const nonceLabel = document.getElementById('activeNonceLabel');
    if (nonceLabel) nonceLabel.textContent = payload.nonce;

    const sigLabel = document.getElementById('activeSignatureLabel');
    if (sigLabel) sigLabel.textContent = payload.signature.substring(0, 24) + '...';

    // 1. Render Normal Canvas QR (208x208)
    const qrContainer = document.getElementById('dynamicQRElement');
    if (qrContainer) {
      qrContainer.innerHTML = '';
      new QRCode(qrContainer, {
        text: JSON.stringify(payload),
        width: 208,
        height: 208,
        colorDark: '#0f172a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }

    // 2. Render Fullscreen / Projector Canvas QR (360x360)
    const fsQrContainer = document.getElementById('fullscreenQRElement');
    if (fsQrContainer) {
      fsQrContainer.innerHTML = '';
      new QRCode(fsQrContainer, {
        text: JSON.stringify(payload),
        width: 360,
        height: 360,
        colorDark: '#070b14',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    }

    AppState.qrSecondsRemaining = payload.expiresIn || 20;
    const cd1 = document.getElementById('qrCountdownSeconds');
    const cd2 = document.getElementById('fsCountdownSeconds');
    if (cd1) cd1.textContent = AppState.qrSecondsRemaining;
    if (cd2) cd2.textContent = AppState.qrSecondsRemaining;
  } catch (err) {
    console.warn('QR Rotation error:', err);
  }
}

function startQRCountdownLoop() {
  clearInterval(AppState.qrCountdownTimer);

  AppState.qrCountdownTimer = setInterval(async () => {
    AppState.qrSecondsRemaining--;
    const cd1 = document.getElementById('qrCountdownSeconds');
    const cd2 = document.getElementById('fsCountdownSeconds');
    if (cd1) cd1.textContent = AppState.qrSecondsRemaining;
    if (cd2) cd2.textContent = AppState.qrSecondsRemaining;

    if (AppState.qrSecondsRemaining <= 0) {
      await rotateTeacherQR();
    }
  }, 1000);
}

async function pollLiveSessionAttendance(sessionId) {
  try {
    const data = await apiFetch(`/api/attendance/session/${sessionId}`);
    const tbody = document.getElementById('tbodySessionAttendance');

    const cp = document.getElementById('counterPresent');
    const ct = document.getElementById('counterTotal');
    const cpc = document.getElementById('counterPercent');
    if (cp) cp.textContent = data.totalPresent;
    if (ct) ct.textContent = data.totalEnrolled;
    if (cpc) cpc.textContent = `${data.attendancePercentage}%`;

    const fsp = document.getElementById('fsCounterPresent');
    const fst = document.getElementById('fsCounterTotal');
    const fspc = document.getElementById('fsCounterPercent');
    if (fsp) fsp.textContent = data.totalPresent;
    if (fst) fst.textContent = data.totalEnrolled;
    if (fspc) fspc.textContent = `${data.attendancePercentage}%`;

    if (!tbody) return;

    if (data.attendances.length === 0) {
      tbody.innerHTML = `<tr><td colspan="5" class="empty-state-cell">Esperando que los estudiantes escaneen el código QR proyectado...</td></tr>`;
      return;
    }

    tbody.innerHTML = data.attendances.map(a => `
      <tr>
        <td><strong>${a.first_name} ${a.last_name}</strong></td>
        <td><code>${a.student_code}</code></td>
        <td><code>${new Date(a.registered_at).toLocaleTimeString()}</code></td>
        <td>
          <span class="status-badge live">
            <span class="status-dot"></span> PRESENTE
          </span>
        </td>
        <td>
          <span class="info-chip">
            <i class="fa-solid fa-qrcode"></i> ${a.distance_meters != null ? `${a.distance_meters}m` : 'HMAC Validado'}
          </span>
        </td>
      </tr>
    `).join('');

  } catch (err) { }
}

// Fullscreen / Theater QR Mode
window.openFullscreenQR = function () {
  if (!AppState.activeSession) {
    showToast('Inicie una sesión de clase para proyectar el código QR.', 'warning');
    return;
  }
  const modal = document.getElementById('modalFullscreenQR');
  if (modal) {
    modal.classList.remove('hidden');
    const title = document.getElementById('fsTopicTitle');
    if (title) title.textContent = AppState.activeSession.topic || 'Asistencia en Vivo';
    rotateTeacherQR();
  }
};

window.closeFullscreenQR = function () {
  const modal = document.getElementById('modalFullscreenQR');
  if (modal) modal.classList.add('hidden');
};

// ================= GRADEBOOK MATRIX CONTROLLER =================
async function loadTeacherGradebookMatrix(groupId) {
  if (!groupId) return;
  try {
    const data = await apiFetch(`/api/grades/group/${groupId}/matrix`);
    const thead = document.getElementById('theadGradebook');
    const tbody = document.getElementById('tbodyGradebook');

    const evals = data.evaluations;
    const matrix = data.matrix;

    // Build Table Header
    thead.innerHTML = `
      <tr>
        <th class="th-student">Estudiante</th>
        ${evals.map(e => `
          <th>
            <strong>${e.name}</strong><br>
            <span class="badge-role teacher">${e.type} (${e.weight}%)</span>
          </th>
        `).join('')}
        <th>Promedio Ponderado</th>
        <th>Progreso</th>
      </tr>
    `;

    if (matrix.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${evals.length + 3}" class="empty-state-cell">No hay estudiantes matriculados en esta asignatura.</td></tr>`;
      return;
    }

    // Build Table Rows
    tbody.innerHTML = matrix.map(row => {
      const student = row.student;
      const grades = row.grades;

      return `
        <tr>
          <td class="td-student">
            <div class="student-matrix-cell">
              <span class="student-matrix-name">${student.first_name} ${student.last_name}</span>
              <span class="student-matrix-code">${student.student_code} • ${student.career}</span>
            </div>
          </td>
          ${grades.map(g => {
        const hasGrade = g.isGraded;
        const scoreText = hasGrade ? Number(g.score).toFixed(1) : '+ Calificar';
        const btnClass = hasGrade ? 'score-badge-btn' : 'score-badge-btn empty';

        return `
              <td>
                <button class="${btnClass}" onclick="openGradeModal(${g.evaluationId}, ${student.student_id}, '${student.first_name} ${student.last_name}', '${g.name}', ${hasGrade ? g.score : 'null'})" title="Haga clic para ingresar o modificar nota">
                  ${scoreText}
                </button>
              </td>
            `;
      }).join('')}
          <td>
            <span class="average-cell ${row.isPassing ? 'passing' : 'failing'}">
              ${row.weightedAverage != null ? row.weightedAverage.toFixed(2) : '-'}
            </span>
          </td>
          <td>
            <span class="badge-pill">${row.totalEvaluatedWeight}% evaluado</span>
          </td>
        </tr>
      `;
    }).join('');

  } catch (err) {
    showToast(`Error al cargar libro de calificaciones: ${err.message}`, 'error');
  }
}

async function loadTeacherEvaluations(groupId) {
  if (!groupId) return;
  try {
    const data = await apiFetch(`/api/academic/groups/${groupId}`);
    const evals = data.evaluations;
    const tbody = document.querySelector('#tableTeacherEvaluations tbody');

    let totalWeight = evals.reduce((sum, e) => sum + Number(e.weight), 0);
    document.getElementById('totalWeightBadge').textContent = `${totalWeight}% / 100%`;

    if (evals.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-state-cell">No se han creado evaluaciones para esta asignatura. Pulse "Crear Nueva Actividad".</td></tr>`;
      return;
    }

    tbody.innerHTML = evals.map(e => `
      <tr>
        <td><strong>${e.name}</strong></td>
        <td><span class="badge-role teacher">${e.type}</span></td>
        <td><strong>${e.weight}%</strong></td>
        <td>${e.max_score}</td>
        <td>${e.due_date || 'Sin fecha límite'}</td>
        <td><span class="status-badge live"><span class="status-dot"></span> ${e.status}</span></td>
        <td>
          <button class="btn btn-sm btn-glass text-danger" onclick="deleteEvaluationRecord(${e.id})" title="Eliminar Actividad">
            <i class="fa-solid fa-trash"></i>
          </button>
        </td>
      </tr>
    `).join('');
  } catch (err) { }
}

window.deleteEvaluationRecord = async function (evalId) {
  if (!confirm('¿Está seguro de eliminar esta evaluación y todas las calificaciones asociadas?')) return;
  try {
    await apiFetch(`/api/evaluations/${evalId}`, { method: 'DELETE' });
    showToast('Actividad eliminada correctamente.', 'info');
    await loadTeacherEvaluations(AppState.selectedGroupId);
    await loadTeacherGradebookMatrix(AppState.selectedGroupId);
  } catch (err) {
    showToast(err.message, 'error');
  }
};

window.openCreateEvaluationModal = function () {
  if (!AppState.selectedGroupId) {
    showToast('Por favor seleccione o cree una asignatura primero.', 'warning');
    return;
  }
  document.getElementById('evalGroupId').value = AppState.selectedGroupId;
  document.getElementById('inputEvalName').value = '';
  document.getElementById('inputEvalWeight').value = '20';
  document.getElementById('inputEvalMaxScore').value = '5.0';
  document.getElementById('inputEvalDueDate').value = '';
  document.getElementById('inputEvalDesc').value = '';
  document.getElementById('modalEvaluation').classList.remove('hidden');
};

async function submitEvaluationForm(e) {
  e.preventDefault();
  const payload = {
    group_id: Number(AppState.selectedGroupId),
    name: document.getElementById('inputEvalName').value.trim(),
    type: document.getElementById('selectEvalType').value,
    weight: Number(document.getElementById('inputEvalWeight').value),
    max_score: Number(document.getElementById('inputEvalMaxScore').value),
    due_date: document.getElementById('inputEvalDueDate').value || null,
    description: document.getElementById('inputEvalDesc').value.trim()
  };

  try {
    await apiFetch('/api/evaluations', { method: 'POST', body: payload });
    sfx.playSuccess();
    showToast('¡Actividad creada y ponderación asignada!', 'success');
    document.getElementById('modalEvaluation').classList.add('hidden');
    await loadTeacherEvaluations(AppState.selectedGroupId);
    await loadTeacherGradebookMatrix(AppState.selectedGroupId);
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

async function loadTeacherGradeHistory() {
  try {
    const history = await apiFetch('/api/audit/grades-history');
    const tbody = document.querySelector('#tableTeacherGradeHistory tbody');

    if (history.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" class="empty-state-cell">No hay modificaciones de notas registradas en sus asignaturas.</td></tr>`;
      return;
    }

    tbody.innerHTML = history.map(h => `
      <tr>
        <td><code>${new Date(h.created_at).toLocaleString()}</code></td>
        <td><strong>${h.student_first_name} ${h.student_last_name}</strong></td>
        <td>${h.evaluation_name} (${h.evaluation_weight}%)</td>
        <td>${h.old_score != null ? `<span class="badge-pill">${h.old_score}</span>` : '<em>Inicial</em>'}</td>
        <td><strong class="eval-score-num">${h.new_score}</strong></td>
        <td>${h.change_reason}</td>
      </tr>
    `).join('');
  } catch (err) { }
}

// Grade Modal Actions
window.openGradeModal = function (evalId, studentId, studentName, evalName, currentScore) {
  document.getElementById('gradeEvalId').value = evalId;
  document.getElementById('gradeStudentId').value = studentId;
  document.getElementById('gradeOldScore').value = currentScore != null ? currentScore : '';

  document.getElementById('gradeStudentName').textContent = studentName;
  document.getElementById('gradeEvalName').textContent = evalName;

  const scoreBadge = document.getElementById('gradeOldScoreBadge');
  if (currentScore != null) {
    scoreBadge.textContent = `${Number(currentScore).toFixed(1)} / 5.0`;
    document.getElementById('inputGradeScore').value = currentScore;
    document.getElementById('inputGradeReason').value = 'Ajuste pedagógico y revisión presencial';
  } else {
    scoreBadge.textContent = 'Sin Calificar';
    document.getElementById('inputGradeScore').value = '';
    document.getElementById('inputGradeReason').value = 'Calificación inicial de actividad';
  }

  document.getElementById('modalGrade').classList.remove('hidden');
};

async function submitGradeForm(e) {
  e.preventDefault();
  const evalId = document.getElementById('gradeEvalId').value;
  const studentId = document.getElementById('gradeStudentId').value;
  const score = document.getElementById('inputGradeScore').value;
  const reason = document.getElementById('inputGradeReason').value;
  const feedback = document.getElementById('inputGradeFeedback').value;

  try {
    await apiFetch('/api/grades', {
      method: 'POST',
      body: {
        evaluation_id: Number(evalId),
        student_id: Number(studentId),
        score: Number(score),
        change_reason: reason,
        feedback: feedback
      }
    });

    sfx.playSuccess();
    showToast('¡Calificación guardada y auditada exitosamente!', 'success');
    document.getElementById('modalGrade').classList.add('hidden');
    await loadTeacherGradebookMatrix(AppState.selectedGroupId);
    await loadTeacherGradeHistory();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

// ================= STUDENT DASHBOARD CONTROLLER =================
async function loadStudentDashboard() {
  try {
    const greeting = document.getElementById('studentGreeting');
    if (greeting && AppState.user) {
      greeting.textContent = `Bienvenido/a, ${AppState.user.firstName} ${AppState.user.lastName}`;
    }

    // 1. Load Grades and Weighted Averages
    const data = await apiFetch('/api/grades/my-grades');
    const container = document.getElementById('studentCoursesGradesList');
    const courses = Array.isArray(data?.courses) ? data.courses : (Array.isArray(data) ? data : []);

    if (container) {
      if (courses.length === 0) {
        container.innerHTML = `<div class="glass-card course-grade-card empty-state-cell">No se encuentra matriculado en ningún curso activo actualmente. Puede inscribir asignaturas en el catálogo disponible inferior.</div>`;
      } else {
        container.innerHTML = courses.map(c => {
          const g = c.group || {};
          const stats = c.stats || {};
          const details = Array.isArray(stats.details) ? stats.details : [];

          return `
            <div class="glass-card course-grade-card">
              <div class="course-card-header">
                <div class="course-title-group">
                  <h3>${g.subject_name || 'Asignatura'} (${g.subject_code || 'COD'})</h3>
                  <span class="teacher-name"><i class="fa-solid fa-chalkboard-user"></i> Prof. ${g.teacher_first_name || ''} ${g.teacher_last_name || ''} • Grupo ${g.group_code || ''}</span>
                </div>
                <div class="course-summary-badges">
                  <div class="average-hero-badge">
                    <span class="label">Promedio Ponderado</span>
                    <span class="value">${stats.weightedAverage != null ? stats.weightedAverage.toFixed(2) : '-'}</span>
                  </div>
                </div>
              </div>

              <div class="course-progress-info">
                <span>Evaluado: <strong>${stats.totalEvaluatedWeight || 0}%</strong></span> • 
                <span>Pendiente: <strong>${stats.remainingWeight || 100}%</strong></span> • 
                <span>Proyección para Aprobar (3.0): <strong>${stats.projectedNeededGrade || 'Pendiente'}</strong></span>
              </div>

              <div class="eval-details-grid">
                ${details.length === 0 ? '<div class="empty-state-cell" style="grid-column:1/-1; padding:0.75rem;">Sin evaluaciones creadas por el docente aún.</div>' : details.map(ev => `
                  <div class="eval-item-card">
                    <div>
                      <div class="eval-name">${ev.name}</div>
                      <span class="badge-role teacher">${ev.type} (${ev.weight}%)</span>
                    </div>
                    <div class="eval-score-box">
                      <span class="label">Calificación:</span>
                      <span class="eval-score-num ${ev.isGraded ? '' : 'pending'}">
                        ${ev.isGraded ? `${Number(ev.score).toFixed(1)} / ${ev.maxScore}` : 'Pendiente'}
                      </span>
                    </div>
                    ${ev.feedback ? `<p style="font-size:0.75rem; color:var(--text-muted); margin-top:0.4rem;"><em>"${ev.feedback}"</em></p>` : ''}
                  </div>
                `).join('')}
              </div>
            </div>
          `;
        }).join('');
      }
    }

    // 2. Load Student Attendance History
    const attData = await apiFetch('/api/attendance/my-history');
    const tbody = document.querySelector('#tableStudentAttendanceHistory tbody');
    const records = Array.isArray(attData?.records) ? attData.records : (Array.isArray(attData?.data) ? attData.data : (Array.isArray(attData) ? attData : []));

    if (tbody) {
      if (records.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="empty-state-cell">Aún no tiene registros de asistencia escaneados.</td></tr>`;
      } else {
        tbody.innerHTML = records.map(r => `
          <tr>
            <td><strong>${r.subject_name || 'Asignatura'}</strong> (${r.group_code || ''})</td>
            <td>${r.topic || 'Sesión Académica'}</td>
            <td><code>${r.registered_at ? new Date(r.registered_at).toLocaleString() : '-'}</code></td>
            <td><span class="status-badge live"><span class="status-dot"></span> ${r.status || 'PRESENTE'}</span></td>
            <td><span class="info-chip"><i class="fa-solid fa-qrcode"></i> ${r.verification_method || 'QR'}</span></td>
          </tr>
        `).join('');
      }
    }

    // 3. Load Available Courses for Self-Enrollment
    await loadAvailableCourses();

  } catch (err) {
    showToast(`Error al cargar datos estudiantiles: ${err.message}`, 'error');
  }
}

// Student Self-Enrollment Catalog Methods
window.loadAvailableCourses = async function () {
  try {
    const grid = document.getElementById('availableCoursesGrid');
    if (!grid) return;
    grid.innerHTML = '<div class="empty-state-cell"><i class="fa-solid fa-spinner fa-spin"></i> Cargando catálogo de asignaturas disponibles...</div>';

    const courses = await apiFetch('/api/academic/available-courses');
    if (!courses || courses.length === 0) {
      grid.innerHTML = '<div class="glass-card course-enroll-card empty-state-cell">No hay asignaturas disponibles para matrícula en este momento.</div>';
      return;
    }

    grid.innerHTML = courses.map(c => `
      <div class="glass-card course-enroll-card">
        <div class="course-enroll-header">
          <span class="code-badge">${c.subject_code || 'ASIG'} • Grupo ${c.group_code}</span>
          <h3 style="margin-top: 0.35rem;">${c.subject_name}</h3>
        </div>
        <div class="course-enroll-details">
          <span><i class="fa-solid fa-chalkboard-user"></i> Prof. ${c.teacher_first_name} ${c.teacher_last_name}</span>
          <span><i class="fa-regular fa-clock"></i> ${c.schedule || 'Por acordar'}</span>
          <span><i class="fa-solid fa-location-dot"></i> ${c.classroom || 'Por definir'}</span>
          <span><i class="fa-solid fa-users"></i> ${c.enrolled_count || 0} estudiantes inscritos</span>
        </div>
        <div>
          ${c.is_enrolled
        ? `<button class="btn btn-secondary btn-block" disabled><i class="fa-solid fa-check"></i> Ya estás matriculado</button>`
        : `<button class="btn btn-primary btn-block" onclick="enrollStudentInCourse(${c.id})"><i class="fa-solid fa-plus"></i> Matricularme en este Curso</button>`
      }
        </div>
      </div>
    `).join('');
  } catch (err) {
    const grid = document.getElementById('availableCoursesGrid');
    if (grid) grid.innerHTML = `<div class="empty-state-cell text-danger">Error al cargar catálogo: ${err.message}</div>`;
  }
};

window.enrollStudentInCourse = async function (groupId) {
  try {
    await apiFetch('/api/academic/enrollments', {
      method: 'POST',
      body: { group_id: Number(groupId) }
    });
    sfx.playSuccess();
    showToast('¡Te has matriculado exitosamente en el curso!', 'success');
    await loadStudentDashboard();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
};

// ================= REAL WEBRTC CAMERA SCANNER (HTML5 + jsQR) =================
async function startCameraScanner() {
  const video = document.getElementById('scannerVideo');
  const badge = document.getElementById('cameraStatusBadge');
  const statusText = document.getElementById('cameraStatusText');

  if (AppState.isCameraRunning) return;

  try {
    const constraints = {
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 640 },
        height: { ideal: 480 }
      },
      audio: false
    };

    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    }

    AppState.cameraStream = stream;
    video.srcObject = stream;
    await video.play();

    AppState.isCameraRunning = true;
    badge.className = 'status-badge live pulse';
    statusText.textContent = 'CÁMARA ACTIVA • ESCANEANDO';
    showToast('Cámara iniciada. Apunte al código QR del profesor.', 'info');

    scanVideoFrame();

  } catch (err) {
    console.error('Camera access error:', err);
    sfx.playError();
    showToast(`No se pudo activar la cámara: ${err.message}. Puede usar el botón de Escaneo Directo.`, 'warning');
    badge.className = 'status-badge closed';
    statusText.textContent = 'CÁMARA NO DISPONIBLE';
  }
}

function stopCameraStream() {
  if (AppState.cameraStream) {
    AppState.cameraStream.getTracks().forEach(track => track.stop());
    AppState.cameraStream = null;
  }
  if (AppState.scanAnimFrameId) {
    cancelAnimationFrame(AppState.scanAnimFrameId);
    AppState.scanAnimFrameId = null;
  }

  const video = document.getElementById('scannerVideo');
  if (video) video.srcObject = null;

  AppState.isCameraRunning = false;
  const badge = document.getElementById('cameraStatusBadge');
  const statusText = document.getElementById('cameraStatusText');
  if (badge) badge.className = 'status-badge closed';
  if (statusText) statusText.textContent = 'CÁMARA APAGADA';
}

function scanVideoFrame() {
  if (!AppState.isCameraRunning) return;

  const video = document.getElementById('scannerVideo');
  const canvas = document.getElementById('scannerCanvas');

  if (video.readyState === video.HAVE_ENOUGH_DATA && !AppState.isScanCooldown) {
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

    if (window.jsQR) {
      const code = jsQR(imageData.data, imageData.width, imageData.height, {
        inversionAttempts: 'dontInvert'
      });

      if (code && code.data) {
        handleScannedQRString(code.data);
      }
    }
  }

  AppState.scanAnimFrameId = requestAnimationFrame(scanVideoFrame);
}

async function handleScannedQRString(qrString) {
  try {
    AppState.isScanCooldown = true;
    let payload;
    try {
      payload = JSON.parse(qrString);
    } catch (e) {
      throw new Error('El código QR escaneado no tiene un formato JSON válido.');
    }

    if (!payload.sessionId || !payload.signature) {
      throw new Error('El código QR escaneado no pertenece a una sesión de NexusAcademic.');
    }

    showToast('Código QR detectado. Verificando firma criptográfica...', 'info');

    // Register attendance via API
    await apiFetch('/api/attendance/register', {
      method: 'POST',
      body: {
        sessionId: payload.sessionId,
        nonce: payload.nonce,
        timestamp: payload.timestamp,
        signature: payload.signature,
        latitude: AppState.userCoordinates.lat,
        longitude: AppState.userCoordinates.lng
      }
    });

    sfx.playSuccess();
    showToast('¡Asistencia registrada y verificada con éxito!', 'success');

    // In-scanner visual feedback pill
    const notifPill = document.getElementById('scannerNotificationPill');
    const notifText = document.getElementById('scannerNotifText');
    if (notifPill) {
      notifText.textContent = '¡Asistencia registrada con éxito!';
      notifPill.classList.add('show');
      setTimeout(() => notifPill.classList.remove('show'), 3500);
    }

    await loadStudentDashboard();

    // 4 seconds cooldown before allowing another scan
    setTimeout(() => {
      AppState.isScanCooldown = false;
    }, 4000);

  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
    setTimeout(() => {
      AppState.isScanCooldown = false;
    }, 3000);
  }
}

// Student Fast Simulated Scan
async function simulateStudentScan() {
  try {
    showToast('Sincronizando con la sesión activa del aula...', 'info');

    // 1. Fetch student enrolled active courses
    const gradesData = await apiFetch('/api/grades/my-grades');
    if (!gradesData.courses || gradesData.courses.length === 0) {
      throw new Error('No estás matriculado en ningún curso activo.');
    }

    const firstGroup = gradesData.courses[0].group;
    const groupDetails = await apiFetch(`/api/academic/groups/${firstGroup.group_id}`);

    if (!groupDetails.activeSession) {
      throw new Error(`No hay sesión de clase activa en este momento para ${firstGroup.subject_name}.`);
    }

    // 2. Fetch rotating QR token
    const tokenData = await apiFetch(`/api/sessions/${groupDetails.activeSession.id}/qr-token`);
    const payload = tokenData.qrPayload;

    // 3. Register Attendance
    await apiFetch('/api/attendance/register', {
      method: 'POST',
      body: {
        sessionId: payload.sessionId,
        nonce: payload.nonce,
        timestamp: payload.timestamp,
        signature: payload.signature,
        latitude: AppState.userCoordinates.lat,
        longitude: AppState.userCoordinates.lng
      }
    });

    sfx.playSuccess();
    showToast('¡Asistencia registrada y verificada con éxito!', 'success');

    const notifPill = document.getElementById('scannerNotificationPill');
    if (notifPill) {
      notifPill.classList.add('show');
      setTimeout(() => notifPill.classList.remove('show'), 3000);
    }

    await loadStudentDashboard();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

async function submitManualStudentPayload() {
  const textarea = document.getElementById('inputManualQRPayload');
  const raw = textarea.value.trim();
  if (!raw) {
    showToast('Por favor ingrese el payload JSON del código QR.', 'warning');
    return;
  }

  try {
    await handleScannedQRString(raw);
    textarea.value = '';
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

// ================= DOM INITIALIZATION & EVENT HANDLERS =================
document.addEventListener('DOMContentLoaded', () => {
  initLiveClock();
  initGeolocation();

  // Check existing session
  checkAuthSession();

  // Tab switching between Login and Register
  const btnTabLogin = document.getElementById('btnAuthTabLogin');
  const btnTabRegister = document.getElementById('btnAuthTabRegister');
  const loginContainer = document.getElementById('authLoginContainer');
  const registerContainer = document.getElementById('authRegisterContainer');

  function switchToLoginTab() {
    btnTabLogin.classList.add('active');
    btnTabRegister.classList.remove('active');
    loginContainer.classList.remove('hidden');
    registerContainer.classList.add('hidden');
  }

  function switchToRegisterTab() {
    btnTabRegister.classList.add('active');
    btnTabLogin.classList.remove('active');
    registerContainer.classList.remove('hidden');
    loginContainer.classList.add('hidden');
  }

  if (btnTabLogin) btnTabLogin.addEventListener('click', switchToLoginTab);
  if (btnTabRegister) btnTabRegister.addEventListener('click', switchToRegisterTab);

  const btnSwitchToReg = document.getElementById('btnSwitchToRegister');
  const btnSwitchToLog = document.getElementById('btnSwitchToLogin');
  if (btnSwitchToReg) btnSwitchToReg.addEventListener('click', switchToRegisterTab);
  if (btnSwitchToLog) btnSwitchToLog.addEventListener('click', switchToLoginTab);

  // Role selector toggling student/teacher fields
  const regRole = document.getElementById('regRole');
  if (regRole) {
    regRole.addEventListener('change', (e) => {
      const isStudent = e.target.value === 'STUDENT';
      const studentFields = document.getElementById('groupStudentFields');
      const teacherFields = document.getElementById('groupTeacherFields');
      const institutionalKey = document.getElementById('groupInstitutionalKey');
      if (isStudent) {
        if (studentFields) studentFields.classList.remove('hidden');
        if (teacherFields) teacherFields.classList.add('hidden');
        if (institutionalKey) institutionalKey.classList.add('hidden');
      } else {
        if (studentFields) studentFields.classList.add('hidden');
        if (teacherFields) teacherFields.classList.remove('hidden');
        if (institutionalKey) institutionalKey.classList.remove('hidden');
      }
    });
  }

  // Registration Form
  const formRegister = document.getElementById('formRegister');
  if (formRegister) {
    formRegister.addEventListener('submit', handleRegisterSubmit);
  }

  // Login Form Submission
  const formLogin = document.getElementById('formLogin');
  if (formLogin) {
    formLogin.addEventListener('submit', (e) => {
      e.preventDefault();
      const email = document.getElementById('inputEmail').value.trim();
      const pass = document.getElementById('inputPassword').value;
      login(email, pass);
    });
  }

  // Toggle password visibility
  const btnToggle = document.getElementById('btnTogglePassword');
  if (btnToggle) {
    btnToggle.addEventListener('click', () => {
      const passInput = document.getElementById('inputPassword');
      const icon = document.querySelector('#btnTogglePassword i');
      if (passInput.type === 'password') {
        passInput.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
      } else {
        passInput.type = 'password';
        icon.className = 'fa-regular fa-eye';
      }
    });
  }

  // Logout Button
  const btnLogout = document.getElementById('btnLogout');
  if (btnLogout) btnLogout.addEventListener('click', logout);

  const btnBrand = document.getElementById('btnBrandHome');
  if (btnBrand) {
    btnBrand.addEventListener('click', () => {
      if (AppState.user) {
        if (AppState.user.role === 'ADMIN') showView('viewAdmin');
        else if (AppState.user.role === 'TEACHER') showView('viewTeacher');
        else if (AppState.user.role === 'STUDENT') showView('viewStudent');
      }
    });
  }

  // Tab switching across all dashboards
  document.querySelectorAll('.tabs-nav-bar').forEach(nav => {
    nav.querySelectorAll('.tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        nav.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const targetTabId = btn.getAttribute('data-tab');
        const parentSection = btn.closest('.view-section');
        parentSection.querySelectorAll('.tab-panel').forEach(panel => {
          panel.classList.remove('active');
          panel.classList.add('hidden');
        });

        const targetPanel = document.getElementById(targetTabId);
        if (targetPanel) {
          targetPanel.classList.remove('hidden');
          targetPanel.classList.add('active');
        }
      });
    });
  });

  // Admin Buttons & Modals
  const btnNewGrp = document.getElementById('btnModalNewGroup');
  if (btnNewGrp) btnNewGrp.addEventListener('click', window.openCreateGroupModal);

  const btnNewTeach = document.getElementById('btnModalNewTeacher');
  if (btnNewTeach) btnNewTeach.addEventListener('click', () => {
    showToast('Para crear docentes nuevos, utilice el formulario de Registro o la API.', 'info');
  });

  const btnNewSub = document.getElementById('btnModalNewSubject');
  if (btnNewSub) btnNewSub.addEventListener('click', window.openSubjectModal);

  const btnNewEnroll = document.getElementById('btnModalNewEnrollment');
  if (btnNewEnroll) btnNewEnroll.addEventListener('click', window.openEnrollmentModal);

  // Admin Modal Form Submits
  const formGroup = document.getElementById('formGroup');
  if (formGroup) formGroup.addEventListener('submit', submitGroupForm);
  const btnCloseGrp = document.getElementById('btnCloseGroupModal');
  const btnCancelGrp = document.getElementById('btnCancelGroupModal');
  if (btnCloseGrp) btnCloseGrp.addEventListener('click', () => document.getElementById('modalGroup').classList.add('hidden'));
  if (btnCancelGrp) btnCancelGrp.addEventListener('click', () => document.getElementById('modalGroup').classList.add('hidden'));

  const formTeacher = document.getElementById('formTeacher');
  if (formTeacher) formTeacher.addEventListener('submit', submitTeacherForm);
  const btnCloseTeach = document.getElementById('btnCloseTeacherModal');
  const btnCancelTeach = document.getElementById('btnCancelTeacherModal');
  if (btnCloseTeach) btnCloseTeach.addEventListener('click', () => document.getElementById('modalTeacher').classList.add('hidden'));
  if (btnCancelTeach) btnCancelTeach.addEventListener('click', () => document.getElementById('modalTeacher').classList.add('hidden'));

  const formEnroll = document.getElementById('formEnrollment');
  if (formEnroll) formEnroll.addEventListener('submit', submitEnrollmentForm);
  const btnCloseEnroll = document.getElementById('btnCloseEnrollModal');
  const btnCancelEnroll = document.getElementById('btnCancelEnrollModal');
  if (btnCloseEnroll) btnCloseEnroll.addEventListener('click', () => document.getElementById('modalEnrollment').classList.add('hidden'));
  if (btnCancelEnroll) btnCancelEnroll.addEventListener('click', () => document.getElementById('modalEnrollment').classList.add('hidden'));

  const formSub = document.getElementById('formSubject');
  if (formSub) formSub.addEventListener('submit', submitSubjectForm);
  const btnCloseSub = document.getElementById('btnCloseSubjectModal');
  const btnCancelSub = document.getElementById('btnCancelSubjectModal');
  if (btnCloseSub) btnCloseSub.addEventListener('click', () => document.getElementById('modalSubject').classList.add('hidden'));
  if (btnCancelSub) btnCancelSub.addEventListener('click', () => document.getElementById('modalSubject').classList.add('hidden'));

  // Teacher Group Selector & Direct Course Management
  const selectTeachGrp = document.getElementById('selectTeacherGroup');
  if (selectTeachGrp) {
    selectTeachGrp.addEventListener('change', (e) => {
      onTeacherGroupSelected(e.target.value);
    });
  }

  // Teacher Custom Course Creation Modal Triggers
  const btnTeacherCreateCourse = document.getElementById('btnTeacherOpenCreateCourse');
  if (btnTeacherCreateCourse) btnTeacherCreateCourse.addEventListener('click', window.openTeacherCreateCourseModal);

  const btnTeacherEditCourse = document.getElementById('btnTeacherEditCurrentCourse');
  if (btnTeacherEditCourse) btnTeacherEditCourse.addEventListener('click', window.openTeacherEditCourseModal);

  const btnTeacherDeleteCourse = document.getElementById('btnTeacherDeleteCurrentCourse');
  if (btnTeacherDeleteCourse) btnTeacherDeleteCourse.addEventListener('click', window.teacherDeleteCurrentCourse);

  const formTeacherCourse = document.getElementById('formTeacherCourse');
  if (formTeacherCourse) formTeacherCourse.addEventListener('submit', submitTeacherCourseForm);

  const btnCloseTeacherCourse = document.getElementById('btnCloseTeacherCourseModal');
  const btnCancelTeacherCourse = document.getElementById('btnCancelTeacherCourseModal');
  if (btnCloseTeacherCourse) btnCloseTeacherCourse.addEventListener('click', () => document.getElementById('modalTeacherCourse').classList.add('hidden'));
  if (btnCancelTeacherCourse) btnCancelTeacherCourse.addEventListener('click', () => document.getElementById('modalTeacherCourse').classList.add('hidden'));

  // Teacher Session Buttons
  const btnStartSess = document.getElementById('btnStartSession');
  if (btnStartSess) btnStartSess.addEventListener('click', startTeacherSession);

  const btnCloseSess = document.getElementById('btnCloseSession');
  if (btnCloseSess) btnCloseSess.addEventListener('click', closeTeacherSession);

  // Fullscreen QR / Projector Mode triggers
  const btnMaxQR = document.getElementById('btnMaximizeQR');
  if (btnMaxQR) btnMaxQR.addEventListener('click', window.openFullscreenQR);

  const qrFrame = document.getElementById('qrFrameWrapper');
  if (qrFrame) {
    qrFrame.style.cursor = 'pointer';
    qrFrame.title = 'Haga clic para agrandar o proyectar';
    qrFrame.addEventListener('click', window.openFullscreenQR);
  }

  const btnCloseFsQR = document.getElementById('btnCloseFullscreenQR');
  if (btnCloseFsQR) btnCloseFsQR.addEventListener('click', window.closeFullscreenQR);

  // Close Fullscreen QR on ESC key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.key === 'Esc') {
      window.closeFullscreenQR();
    }
  });

  // Teacher Evaluations Modal
  const btnNewEval = document.getElementById('btnModalNewEvaluation');
  if (btnNewEval) btnNewEval.addEventListener('click', window.openCreateEvaluationModal);

  const formEval = document.getElementById('formEvaluation');
  if (formEval) formEval.addEventListener('submit', submitEvaluationForm);

  const btnCloseEval = document.getElementById('btnCloseEvalModal');
  const btnCancelEval = document.getElementById('btnCancelEvalModal');
  if (btnCloseEval) btnCloseEval.addEventListener('click', () => document.getElementById('modalEvaluation').classList.add('hidden'));
  if (btnCancelEval) btnCancelEval.addEventListener('click', () => document.getElementById('modalEvaluation').classList.add('hidden'));

  // Teacher Grade Modal Form
  const formGrade = document.getElementById('formGrade');
  if (formGrade) formGrade.addEventListener('submit', submitGradeForm);
  const btnCloseGrd = document.getElementById('btnCloseGradeModal');
  const btnCancelGrd = document.getElementById('btnCancelGradeModal');
  if (btnCloseGrd) btnCloseGrd.addEventListener('click', () => document.getElementById('modalGrade').classList.add('hidden'));
  if (btnCancelGrd) btnCancelGrd.addEventListener('click', () => document.getElementById('modalGrade').classList.add('hidden'));

  // Camera Buttons
  const btnStartCam = document.getElementById('btnStartCamera');
  if (btnStartCam) btnStartCam.addEventListener('click', startCameraScanner);

  const btnStopCam = document.getElementById('btnStopCamera');
  if (btnStopCam) btnStopCam.addEventListener('click', stopCameraStream);

  // Student Actions
  const btnSim = document.getElementById('btnSimulateScan');
  if (btnSim) btnSim.addEventListener('click', simulateStudentScan);

  const btnManual = document.getElementById('btnSubmitManualPayload');
  if (btnManual) btnManual.addEventListener('click', submitManualStudentPayload);

  const btnRefStud = document.getElementById('btnRefreshStudentData');
  if (btnRefStud) btnRefStud.addEventListener('click', loadStudentDashboard);

  const btnRefCourses = document.getElementById('btnRefreshAvailableCourses');
  if (btnRefCourses) btnRefCourses.addEventListener('click', window.loadAvailableCourses);

  const btnRefTeach = document.getElementById('btnRefreshTeacherData');
  if (btnRefTeach) btnRefTeach.addEventListener('click', () => onTeacherGroupSelected(AppState.selectedGroupId));

  const btnRefAdm = document.getElementById('btnRefreshAdminData');
  if (btnRefAdm) btnRefAdm.addEventListener('click', loadAdminDashboard);

  // CSV Export
  const btnCsv = document.getElementById('btnExportGradesCSV');
  if (btnCsv) {
    btnCsv.addEventListener('click', () => {
      showToast('Exportando matriz de calificaciones...', 'info');
      window.open(`/api/grades/group/${AppState.selectedGroupId}/matrix`, '_blank');
    });
  }

  // Profile & Account Management Form Listeners
  const formProfile = document.getElementById('formUpdateProfile');
  if (formProfile) formProfile.addEventListener('submit', handleProfileUpdateSubmit);

  const formProfilePass = document.getElementById('formProfileChangePassword');
  if (formProfilePass) formProfilePass.addEventListener('submit', handleProfileChangePasswordSubmit);

  const btnOpenDel = document.getElementById('btnOpenDeleteAccountModal');
  if (btnOpenDel) btnOpenDel.addEventListener('click', openDeleteAccountModal);

  const btnCloseDel = document.getElementById('btnCloseDeleteAccountModal');
  if (btnCloseDel) btnCloseDel.addEventListener('click', closeDeleteAccountModal);

  const btnCancelDel = document.getElementById('btnCancelDeleteAccount');
  if (btnCancelDel) btnCancelDel.addEventListener('click', closeDeleteAccountModal);

  const formDelAcc = document.getElementById('formDeleteAccount');
  if (formDelAcc) formDelAcc.addEventListener('submit', handleDeleteAccountSubmit);

  // Master Developer Tab Switchers
  const btnDevTabUsers = document.getElementById('btnDevTabUsers');
  const btnDevTabSecurity = document.getElementById('btnDevTabSecurity');
  const btnDevTabDb = document.getElementById('btnDevTabDb');
  const tabDevUsers = document.getElementById('tabDevUsers');
  const tabDevSecurity = document.getElementById('tabDevSecurity');
  const tabDevDb = document.getElementById('tabDevDb');

  function switchDevTab(activeBtn, activeTab) {
    [btnDevTabUsers, btnDevTabSecurity, btnDevTabDb].forEach(b => b && b.classList.remove('active'));
    [tabDevUsers, tabDevSecurity, tabDevDb].forEach(t => t && t.classList.add('hidden'));
    activeBtn.classList.add('active');
    activeTab.classList.remove('hidden');
  }

  if (btnDevTabUsers && tabDevUsers) btnDevTabUsers.addEventListener('click', () => switchDevTab(btnDevTabUsers, tabDevUsers));
  if (btnDevTabSecurity && tabDevSecurity) btnDevTabSecurity.addEventListener('click', () => switchDevTab(btnDevTabSecurity, tabDevSecurity));
  if (btnDevTabDb && tabDevDb) btnDevTabDb.addEventListener('click', () => switchDevTab(btnDevTabDb, tabDevDb));

  const btnRefMaster = document.getElementById('btnRefreshMasterDevData');
  if (btnRefMaster) btnRefMaster.addEventListener('click', loadMasterDevDashboard);

  const inputSearchDev = document.getElementById('inputSearchDevUsers');
  if (inputSearchDev) inputSearchDev.addEventListener('input', renderDevUsersFiltered);

  const selectFilterDevRole = document.getElementById('selectFilterDevRole');
  if (selectFilterDevRole) selectFilterDevRole.addEventListener('change', renderDevUsersFiltered);

  const formDevEdit = document.getElementById('formDevEditUser');
  if (formDevEdit) formDevEdit.addEventListener('submit', handleDevEditUserSubmit);

  const btnCloseDevEdit = document.getElementById('btnCloseDevEditUserModal');
  const btnCancelDevEdit = document.getElementById('btnCancelDevEditUser');
  const modalDevEdit = document.getElementById('modalDevEditUser');
  const closeDevModal = () => modalDevEdit && modalDevEdit.classList.add('hidden');
  if (btnCloseDevEdit) btnCloseDevEdit.addEventListener('click', closeDevModal);
  if (btnCancelDevEdit) btnCancelDevEdit.addEventListener('click', closeDevModal);

  // Master Dev Create User Modal
  const btnOpenDevCreate = document.getElementById('btnDevOpenCreateUser');
  const modalDevCreate = document.getElementById('modalDevCreateUser');
  const btnCloseDevCreate = document.getElementById('btnCloseDevCreateUserModal');
  const btnCancelDevCreate = document.getElementById('btnCancelDevCreateUser');
  const formDevCreate = document.getElementById('formDevCreateUser');
  const closeDevCreateModal = () => modalDevCreate && modalDevCreate.classList.add('hidden');

  if (btnOpenDevCreate && modalDevCreate) {
    btnOpenDevCreate.addEventListener('click', () => {
      formDevCreate && formDevCreate.reset();
      modalDevCreate.classList.remove('hidden');
    });
  }
  if (btnCloseDevCreate) btnCloseDevCreate.addEventListener('click', closeDevCreateModal);
  if (btnCancelDevCreate) btnCancelDevCreate.addEventListener('click', closeDevCreateModal);
  if (formDevCreate) formDevCreate.addEventListener('submit', handleDevCreateUserSubmit);

  // Initialize Theme System
  initThemeSystem();
});

// ================= MASTER DEVELOPER & SUPERADMIN CONTROLLER =================
let allDevUsersList = [];

async function loadMasterDevDashboard() {
  try {
    // 1. Fetch System Telemetry & KPIs
    const overview = await apiFetch('/api/dev/overview');
    const tel = overview?.telemetry || {};
    const roles = tel.roles || {};
    const tables = tel.tables || {};

    const elDbStatus = document.getElementById('statDevDbStatus');
    if (elDbStatus) elDbStatus.textContent = 'Conectado';

    const elTablesTotal = document.getElementById('statDevTablesTotal');
    if (elTablesTotal) elTablesTotal.textContent = `${Object.keys(tables).length} tablas relacionales (MySQL)`;

    const elTotalUsers = document.getElementById('statDevTotalUsers');
    if (elTotalUsers) elTotalUsers.textContent = roles.total || 0;

    const elUsersBreakdown = document.getElementById('statDevUsersBreakdown');
    if (elUsersBreakdown) {
      elUsersBreakdown.textContent = `${roles.teacher || 0} docentes • ${roles.student || 0} alumnos • ${roles.admin || 0} directivos`;
    }

    const elActiveKey = document.getElementById('statDevActiveKey');
    if (elActiveKey) elActiveKey.textContent = tel.teacherKey || 'PROTEGIDO';

    const elAuditCount = document.getElementById('statDevAuditCount');
    if (elAuditCount) elAuditCount.textContent = tables.audit_logs || 0;

    const elTeacherKeyDisp = document.getElementById('devDisplayTeacherKey');
    if (elTeacherKeyDisp) elTeacherKeyDisp.textContent = tel.teacherKey || 'NEXUS-DOCENTE-AUTH-2026';

    const elAdminKeyDisp = document.getElementById('devDisplayAdminKey');
    if (elAdminKeyDisp) elAdminKeyDisp.textContent = tel.adminKey || 'NEXUS-ADMIN-MASTER-2026';

    // 2. Render Database Tables Matrix
    const dbTbody = document.querySelector('#tableDevDbRows tbody');
    if (dbTbody) {
      const tableDescriptions = {
        users: 'Identidades y credenciales de acceso seguras',
        teachers: 'Perfiles del cuerpo docente y facultades',
        students: 'Matrículas estudiantiles, códigos y carreras',
        subjects: 'Catálogo de materias y créditos académicos',
        course_groups: 'Cursos abiertos, docentes y horarios',
        enrollments: 'Inscripciones de alumnos en grupos',
        class_sessions: 'Sesiones de clase con control de asistencia',
        attendance_tokens: 'Tokens criptográficos rotativos para QR',
        attendances: 'Registros de asistencia escaneados',
        evaluations: 'Rúbricas de calificación y porcentajes',
        grades: 'Calificaciones registradas por docentes',
        grade_history: 'Bitácora inmutable de cambios de notas',
        audit_logs: 'Historial forense de seguridad del sistema',
        institutions: 'Configuración institucional y escalas de nota',
        academic_periods: 'Ciclos y semestres académicos'
      };

      dbTbody.innerHTML = Object.entries(tables).map(([tName, rowCount]) => `
        <tr>
          <td><code style="color:#c084fc; font-weight:600;">${tName}</code></td>
          <td>${tableDescriptions[tName] || 'Entidad relacional'}</td>
          <td><strong>${rowCount}</strong> registros</td>
          <td><span class="status-badge live"><span class="status-dot"></span> Sincronizada</span></td>
        </tr>
      `).join('');
    }

    // 3. Fetch and Render All Users
    await loadDevUsersTable();

  } catch (err) {
    showToast(`Error al cargar Consola Master: ${err.message}`, 'error');
  }
}

async function loadDevUsersTable() {
  const tbody = document.querySelector('#tableDevUsers tbody');
  if (!tbody) return;

  tbody.innerHTML = '<tr><td colspan="8" class="empty-state-cell"><i class="fa-solid fa-spinner fa-spin"></i> Cargando directorio maestro de cuentas...</td></tr>';

  try {
    const data = await apiFetch('/api/dev/users');
    allDevUsersList = Array.isArray(data?.users) ? data.users : [];
    renderDevUsersFiltered();
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="8" class="empty-state-cell text-danger">Error: ${err.message}</td></tr>`;
  }
}

function renderDevUsersFiltered() {
  const tbody = document.querySelector('#tableDevUsers tbody');
  if (!tbody) return;

  const search = (document.getElementById('inputSearchDevUsers')?.value || '').toLowerCase().trim();
  const roleFilter = document.getElementById('selectFilterDevRole')?.value || 'ALL';

  const filtered = allDevUsersList.filter(u => {
    const matchesSearch = !search ||
      u.email.toLowerCase().includes(search) ||
      u.first_name.toLowerCase().includes(search) ||
      u.last_name.toLowerCase().includes(search) ||
      (u.phone && u.phone.includes(search));

    const matchesRole = roleFilter === 'ALL' || u.role === roleFilter;

    return matchesSearch && matchesRole;
  });

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state-cell">No se encontraron usuarios coincidentes.</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(u => {
    const isMaster = u.role === 'SUPERADMIN';
    const isActive = u.status === 'ACTIVE';

    let profileInfo = '-';
    if (u.role === 'TEACHER') {
      profileInfo = `<code>${u.teacher_code || 'DOC'}</code> • ${u.department || 'Facultad'}`;
    } else if (u.role === 'STUDENT') {
      profileInfo = `<code>${u.student_code || 'EST'}</code> • ${u.career || 'Carrera'} (Sem ${u.semester || 1})`;
    } else if (u.role === 'ADMIN') {
      profileInfo = 'Dirección / Rectoría';
    } else if (u.role === 'SUPERADMIN') {
      profileInfo = 'Programador del Sistema';
    }

    const roleBadgeClass = u.role.toLowerCase();

    return `
      <tr>
        <td><code>#${u.id}</code></td>
        <td>
          <div style="font-weight:600;">${u.first_name} ${u.last_name}</div>
          <small style="color:var(--text-muted);">${u.email}</small>
        </td>
        <td>
          ${u.phone ? `<span><i class="fa-solid fa-phone" style="font-size:0.75rem; color:var(--text-muted);"></i> ${u.phone}</span>` : '<span style="color:var(--text-muted);">Sin teléfono</span>'}
        </td>
        <td>
          <span class="badge-role ${roleBadgeClass}">${u.role}</span>
        </td>
        <td><small>${profileInfo}</small></td>
        <td>
          <span class="status-badge ${isActive ? 'active-user' : 'inactive-user'}">
            <span class="status-dot"></span> ${u.status}
          </span>
        </td>
        <td><small>${u.created_at ? new Date(u.created_at).toLocaleDateString() : '-'}</small></td>
        <td>
          <div style="display:flex; gap:0.35rem; align-items:center;">
            ${!isMaster ? `
              <button class="btn-dev-action ${isActive ? 'suspend' : 'activate'}" onclick="toggleDevUserStatus(${u.id}, '${isActive ? 'INACTIVE' : 'ACTIVE'}')" title="${isActive ? 'Suspender acceso' : 'Reactivar acceso'}">
                <i class="fa-solid ${isActive ? 'fa-user-slash' : 'fa-user-check'}"></i>
                ${isActive ? 'Suspender' : 'Activar'}
              </button>
              <button class="btn-dev-action" onclick="openDevEditUserModal(${u.id})" title="Editar rol o restablecer clave">
                <i class="fa-solid fa-user-pen"></i> Editar
              </button>
              <button class="btn-dev-action delete" onclick="deleteDevUser(${u.id}, '${u.email}')" title="Eliminar definitivamente">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            ` : `
              <span class="badge-role superadmin" style="font-size:0.7rem;"><i class="fa-solid fa-lock"></i> Master Dev</span>
            `}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// Master Dev Actions
window.toggleDevUserStatus = async function(userId, newStatus) {
  try {
    const res = await apiFetch(`/api/dev/users/${userId}/status`, {
      method: 'PUT',
      body: { status: newStatus }
    });
    sfx.playSuccess();
    showToast(res.message || 'Estado de cuenta actualizado.', 'success');
    await loadDevUsersTable();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
};

window.openDevEditUserModal = function(userId) {
  const user = allDevUsersList.find(u => Number(u.id) === Number(userId));
  if (!user) return;

  document.getElementById('devEditUserId').value = user.id;
  document.getElementById('devEditUserName').textContent = `${user.first_name} ${user.last_name}`;
  document.getElementById('devEditUserEmail').textContent = `${user.email} • Tel: ${user.phone || 'N/A'}`;
  document.getElementById('devEditUserRole').value = user.role;
  document.getElementById('devEditUserStatus').value = user.status;
  document.getElementById('devEditNewPass').value = '';

  document.getElementById('modalDevEditUser').classList.remove('hidden');
};

async function handleDevEditUserSubmit(e) {
  e.preventDefault();
  const userId = document.getElementById('devEditUserId').value;
  const newRole = document.getElementById('devEditUserRole').value;
  const newStatus = document.getElementById('devEditUserStatus').value;
  const newPass = document.getElementById('devEditNewPass').value.trim();

  try {
    // 1. Update Role
    await apiFetch(`/api/dev/users/${userId}/role`, {
      method: 'PUT',
      body: { role: newRole }
    });

    // 2. Update Status
    await apiFetch(`/api/dev/users/${userId}/status`, {
      method: 'PUT',
      body: { status: newStatus }
    });

    // 3. Reset Password if provided
    if (newPass) {
      await apiFetch(`/api/dev/users/${userId}/reset-password`, {
        method: 'POST',
        body: { password: newPass }
      });
    }

    sfx.playSuccess();
    showToast('Cambios maestros guardados exitosamente.', 'success');
    document.getElementById('modalDevEditUser').classList.add('hidden');
    await loadDevUsersTable();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

window.deleteDevUser = async function(userId, email) {
  if (!confirm(`¿Está seguro de eliminar definitivamente la cuenta de ${email}?\nEsta acción es irreversible y borrará permanentemente sus datos.`)) {
    return;
  }
  try {
    const res = await apiFetch(`/api/dev/users/${userId}`, { method: 'DELETE' });
    sfx.playSuccess();
    showToast(res.message || 'Cuenta eliminada del sistema.', 'success');
    await loadDevUsersTable();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
};

async function handleDevCreateUserSubmit(e) {
  e.preventDefault();
  const firstName = document.getElementById('devNewFirstName').value.trim();
  const lastName = document.getElementById('devNewLastName').value.trim();
  const email = document.getElementById('devNewEmail').value.trim();
  const phone = document.getElementById('devNewPhone').value.trim();
  const role = document.getElementById('devNewRole').value;
  const password = document.getElementById('devNewPassword').value.trim();
  const code = document.getElementById('devNewCode').value.trim();
  const deptCareer = document.getElementById('devNewDeptCareer').value.trim();

  if (!email || !firstName || !lastName || !password) {
    showToast('Por favor complete todos los campos requeridos.', 'warning');
    return;
  }

  try {
    const res = await apiFetch('/api/dev/users', {
      method: 'POST',
      body: {
        first_name: firstName,
        last_name: lastName,
        email: email,
        phone: phone,
        role: role,
        password: password,
        code: code,
        department_or_career: deptCareer
      }
    });

    sfx.playSuccess();
    showToast(res.message || 'Usuario institucional dado de alta correctamente.', 'success');
    document.getElementById('modalDevCreateUser').classList.add('hidden');
    document.getElementById('formDevCreateUser').reset();
    await loadDevUsersTable();
    await loadMasterDevDashboard();
  } catch (err) {
    sfx.playError();
    showToast(err.message, 'error');
  }
}

// ================= THEME SYSTEM (LIGHT / DARK DUAL MODE) =================
function initThemeSystem() {
  const savedTheme = localStorage.getItem('nexus_academic_theme') || 'dark';
  applyTheme(savedTheme);

  const btnToggle = document.getElementById('btnThemeToggle');
  if (btnToggle && !btnToggle.dataset.bound) {
    btnToggle.dataset.bound = 'true';
    btnToggle.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const nextTheme = current === 'dark' ? 'light' : 'dark';
      applyTheme(nextTheme);
      sfx.playSuccess();
      showToast(`Modo visual cambiado a ${nextTheme === 'light' ? 'Claro' : 'Oscuro'}.`, 'info');
    });
  }
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('nexus_academic_theme', theme);
  const iconDark = document.querySelector('.theme-icon-dark');
  const iconLight = document.querySelector('.theme-icon-light');
  if (iconDark && iconLight) {
    if (theme === 'light') {
      iconDark.classList.add('hidden');
      iconLight.classList.remove('hidden');
    } else {
      iconDark.classList.remove('hidden');
      iconLight.classList.add('hidden');
    }
  }
}

// Immediate Theme Execution on Script Parse
initThemeSystem();

