const apiBase = '';

const views = {
  LIST: 'list',
  CREATE: 'create',
  EDIT: 'edit',
};

const knownPlacements = new Set(['CRM_DEAL_DETAIL_TAB', 'CRM_DEAL_LIST_MENU', 'HAULS']);

const initialDealContext = readInitialDealContext();

const embeddedMode = detectEmbeddedMode();

const driverKeyword = 'водител';
let referenceDataPromise = null;
let bx24DealLookupPromise = null;
let portalBaseUrlCache = '';

const state = {
  dealId: initialDealContext.id,
  view: views.LIST,
  currentHaulId: null,
  currentHaulSnapshot: null,
  trucks: [],
  materials: [],
  drivers: [],
  hauls: [],
  suppliers: [],
  carriers: [],
  dealMeta: initialDealContext.meta,
  formTemplate: null,
  embedded: embeddedMode,
  role: detectDefaultRole(embeddedMode),
  actor: {
    id: null,
    name: null,
    role: detectDefaultRole(embeddedMode),
  },
  loading: false,
  saving: false,
};

function detectDefaultRole(isEmbedded) {
  return isEmbedded ? 'manager' : 'driver';
}

function detectPortalBaseUrl() {
  if (typeof window === 'undefined') {
    return '';
  }
  const htmlPortal = document.documentElement?.getAttribute('data-portal-url');
  if (typeof htmlPortal === 'string' && htmlPortal.trim()) {
    return htmlPortal.trim();
  }
  const globalPortal = typeof window.BITRIX_PORTAL_URL === 'string' ? window.BITRIX_PORTAL_URL.trim() : '';
  if (globalPortal) {
    return globalPortal;
  }
  if (window.BX24 && typeof window.BX24.getAuth === 'function') {
    try {
      const auth = window.BX24.getAuth();
      const domain = auth?.domain || auth?.DOMAIN;
      if (typeof domain === 'string' && domain.trim()) {
        const normalizedDomain = domain.replace(/^https?:\/\//i, '').replace(/\/+$/, '');
        return `https://${normalizedDomain}`;
      }
    } catch (error) {
      console.warn('Не удалось определить портал из BX24 auth', error);
    }
  }
  const origin = window.location?.origin;
  return typeof origin === 'string' ? origin : '';
}

function getPortalBaseUrl() {
  if (portalBaseUrlCache) {
    return portalBaseUrlCache;
  }
  portalBaseUrlCache = detectPortalBaseUrl();
  return portalBaseUrlCache;
}

function deriveRoleFromBitrixUser(user) {
  if (!user || typeof user !== 'object') {
    return 'manager';
  }

  const candidates = [
    user.WORK_POSITION,
    user.POSITION,
    user.work_position,
    user.position,
  ];

  const hasDriverPosition = candidates.some((value) => isDriverPosition(value));

  return hasDriverPosition ? 'driver' : 'manager';
}

function isDriverPosition(value) {
  if (typeof value !== 'string') {
    return false;
  }
  return value.toLowerCase().includes(driverKeyword);
}

function applyRole(role, context = {}) {
  state.role = role;
  if (typeof context.id !== 'undefined') {
    const numeric = typeof context.id === 'string' ? Number(context.id) : context.id;
    state.actor.id = Number.isFinite(numeric) ? Number(numeric) : context.id;
  }
  if (typeof context.name === 'string') {
    state.actor.name = context.name;
  }
  state.actor.role = role;
}

function applyDriverRoleFromMobileUser(user) {
  if (!user || typeof user !== 'object') {
    applyRole('driver');
    return;
  }

  const context = {
    id: user.id ?? user.bitrix_user_id ?? null,
    name: user.name ?? user.email ?? null,
  };
  applyRole('driver', context);
}

function resetRoleToDefault() {
  applyRole(detectDefaultRole(state.embedded));
}

const elements = {
  app: document.getElementById('app'),
  loadingMessage: document.getElementById('app-loading'),
  dealInput: document.getElementById('deal-id-input'),
  dealTitle: document.getElementById('deal-title'),
  dealIdLabel: document.getElementById('deal-id-label'),
  dealSubtitle: document.getElementById('deal-subtitle'),
  loadButton: document.getElementById('load-hauls'),
  floatingCreate: document.getElementById('floating-create'),
  haulsList: document.getElementById('hauls-list'),
  mainSection: document.querySelector('.app__main'),
  editorPanel: document.getElementById('editor-panel'),
  editorMode: document.getElementById('editor-mode'),
  haulForm: document.getElementById('haul-form'),
  driverSelect: document.getElementById('driver-select'),
  truckSelect: document.getElementById('truck-select'),
  materialSelect: document.getElementById('material-select'),
  supplierSelect: document.getElementById('supplier-select'),
  carrierSelect: document.getElementById('carrier-select'),
  legDistanceInput: document.getElementById('leg-distance-input'),
  unloadFromDisplay: document.getElementById('unload-from-display'),
  unloadFromInput: document.getElementById('unload-from-input'),
  unloadToDisplay: document.getElementById('unload-to-display'),
  unloadToInput: document.getElementById('unload-to-input'),
  editorDealTitle: document.getElementById('editor-deal-title'),
  editorDealMeta: document.getElementById('editor-deal-meta'),
  formError: document.getElementById('form-error'),
  globalError: document.getElementById('app-error'),
  closeEditor: document.getElementById('close-editor'),
  cancelEditor: document.getElementById('cancel-editor'),
  submitHaul: document.getElementById('submit-haul'),
};

const mobileElements = {
  container: document.getElementById('mobile-app'),
  loginSection: document.getElementById('mobile-login'),
  loginForm: document.getElementById('mobile-login-form'),
  loginError: document.getElementById('mobile-login-error'),
  loginButton: document.getElementById('mobile-login-submit'),
  haulsSection: document.getElementById('mobile-hauls'),
  refreshButton: document.getElementById('mobile-refresh'),
  logoutButton: document.getElementById('mobile-logout'),
  loader: document.getElementById('mobile-loader'),
  empty: document.getElementById('mobile-empty'),
  list: document.getElementById('mobile-hauls-list'),
  userName: document.getElementById('mobile-user-name'),
  errorBox: document.getElementById('mobile-hauls-error'),
  dialog: document.getElementById('mobile-haul-dialog'),
  dialogBody: document.getElementById('mobile-haul-dialog-body'),
  dialogTitle: document.getElementById('mobile-haul-dialog-title'),
  dialogMeta: document.getElementById('mobile-haul-dialog-meta'),
  dialogClose: document.getElementById('mobile-haul-dialog-close'),
  dialogBackdrop: document.querySelector('[data-mobile-dialog-dismiss]'),
  dialogError: document.getElementById('mobile-haul-dialog-error'),
  dialogVolumeInput: null,
};

const mobileState = {
  user: null,
  hauls: [],
  loading: false,
  selectedHaulId: null,
};

const mobileViewStates = {
  LOGIN: 'login',
  HAULS: 'hauls',
};

const mobileStorageKey = 'b24-mobile-hauls-login';

function showGlobalError(message) {
  if (!elements.globalError) {
    if (message) {
      console.error(message);
    }
    return;
  }

  if (message) {
    elements.globalError.textContent = message;
    elements.globalError.hidden = false;
  } else {
    clearGlobalError();
  }
}

function clearGlobalError() {
  if (!elements.globalError) {
    return;
  }
  elements.globalError.textContent = '';
  elements.globalError.hidden = true;
}

function detectEmbeddedMode() {
  if (typeof window === 'undefined') {
    return false;
  }

  if (window !== window.top) {
    return true;
  }

  const bootstrap = window.B24_INSTALL_PAYLOAD || null;
  const candidates = [
    bootstrap?.payload,
    bootstrap?.request,
    bootstrap?.get,
    bootstrap?.query,
  ];

  for (const candidate of candidates) {
    if (candidate && hasKnownPlacement(candidate)) {
      return true;
    }
  }

  try {
    const searchParams = new URLSearchParams(window.location.search || '');
    const directPlacement =
      searchParams.get('PLACEMENT') || searchParams.get('placement');
    if (hasKnownPlacement(directPlacement)) {
      return true;
    }

    const optionsRaw =
      searchParams.get('PLACEMENT_OPTIONS') ||
      searchParams.get('placement_options');
    if (optionsRaw && hasKnownPlacement(optionsRaw)) {
      return true;
    }
  } catch (error) {
    console.warn('Не удалось разобрать параметры адресной строки', error);
  }

  return false;
}

function hasKnownPlacement(candidate) {
  if (!candidate) {
    return false;
  }

  if (candidate instanceof URLSearchParams) {
    const direct = candidate.get('PLACEMENT') || candidate.get('placement');
    if (hasKnownPlacement(direct)) {
      return true;
    }

    const options =
      candidate.get('PLACEMENT_OPTIONS') || candidate.get('placement_options');
    return hasKnownPlacement(options);
  }

  if (typeof candidate === 'string') {
    const normalized = candidate.trim();
    if (!normalized) {
      return false;
    }

    if (isKnownPlacementValue(normalized)) {
      return true;
    }

    const parsed = parsePlacementOptions(normalized);
    if (parsed && parsed !== candidate) {
      return hasKnownPlacement(parsed);
    }

    return false;
  }

  if (typeof candidate !== 'object') {
    return false;
  }

  if (typeof candidate.get === 'function') {
    try {
      const value = candidate.get('PLACEMENT') || candidate.get('placement');
      if (hasKnownPlacement(value)) {
        return true;
      }

      const options =
        candidate.get('PLACEMENT_OPTIONS') ||
        candidate.get('placement_options');
      if (options && hasKnownPlacement(options)) {
        return true;
      }
    } catch (error) {
      console.warn('Не удалось прочитать placement из объекта', error);
    }
  }

  const placement = readPlacementFromObject(candidate);
  if (isKnownPlacementValue(placement)) {
    return true;
  }

  const optionsRaw = extractPlacementOptionsRaw(candidate);
  if (optionsRaw && hasKnownPlacement(optionsRaw)) {
    return true;
  }

  return false;
}

function readPlacementFromObject(subject) {
  if (!subject || typeof subject !== 'object') {
    return null;
  }

  const candidates = [
    subject.PLACEMENT,
    subject.placement,
    subject.PLACEMENT_NAME,
    subject.placement_name,
    subject.placementName,
    subject.PLACEMENT_CODE,
    subject.placement_code,
    subject.context?.PLACEMENT,
    subject.context?.placement,
    subject.params?.PLACEMENT,
    subject.params?.placement,
    subject.PARAMS?.PLACEMENT,
    subject.PARAMS?.placement,
  ];

  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.trim() !== '') {
      return candidate;
    }
  }

  return null;
}

function isKnownPlacementValue(value) {
  if (typeof value !== 'string') {
    return false;
  }

  const normalized = value.trim();
  if (!normalized) {
    return false;
  }

  return knownPlacements.has(normalized.toUpperCase());
}

const mobileStorage = {
  getLogin() {
    try {
      return localStorage.getItem(mobileStorageKey) || '';
    } catch (error) {
      console.warn('Не удалось прочитать сохранённый логин', error);
      return '';
    }
  },
  saveLogin(login) {
    if (!login) {
      return;
    }
    try {
      localStorage.setItem(mobileStorageKey, login);
    } catch (error) {
      console.warn('Не удалось сохранить логин', error);
    }
  },
};

const mobileStatusLabels = {
  0: 'Формирование рейса',
  1: 'В работе',
  2: 'Загрузился',
  3: 'Выгрузился',
  4: 'Проверено',
};

const driverVisibleStatuses = new Set([1, 2, 3]);

let fitTimer = null;
let bx24Ready = null;
let bx24InitPromise = null;
let pendingHashSkips = 0;

if (state.embedded) {
  initEmbedded().catch((error) => {
    console.error('Ошибка инициализации', error);
  });
} else {
  initMobile().catch((error) => {
    console.error('Ошибка инициализации мобильного режима', error);
  });
}

async function initEmbedded() {
  detectDarkMode();
  configureEmbedding();
  attachEventHandlers();
  const actorPromise = resolveActorFromBitrix();
  referenceDataPromise = loadReferenceData().catch((error) => {
    console.error('Не удалось загрузить справочники', error);
  });
  const hasDeal = await detectDealId();
  updateDealMeta();
  initRouter();
  actorPromise.catch((error) => {
    console.warn('Не удалось определить пользователя Bitrix24', error);
  });
  if (hasDeal) {
    void loadHauls();
  } else {
    renderList();
  }
  setAppReady(true);
  scheduleFitWindow();
}

async function initMobile() {
  document.body.classList.add('mobile-driver');
  if (mobileElements.container) {
    mobileElements.container.hidden = false;
  }

  attachMobileHandlers();
  await mobileCheckAuth();
}

function attachMobileHandlers() {
  mobileElements.loginForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const formData = new FormData(mobileElements.loginForm);
    const login = String(formData.get('login') ?? '').trim();
    const passwordValue = formData.get('password');
    const password = typeof passwordValue === 'string' ? passwordValue : '';

    if (!login || !password) {
      setMobileLoginError('Введите логин и пароль.');
      return;
    }

    void mobileLogin(login, password);
  });

  mobileElements.refreshButton?.addEventListener('click', () => {
    void loadMobileHauls();
  });

  mobileElements.logoutButton?.addEventListener('click', () => {
    void mobileLogout();
  });

  mobileElements.dialogClose?.addEventListener('click', () => {
    closeMobileHaulDetails();
  });

  mobileElements.dialogBackdrop?.addEventListener('click', () => {
    closeMobileHaulDetails();
  });

  document.addEventListener('keydown', handleMobileDialogKeydown);
}

async function mobileCheckAuth() {
  try {
    const data = await request('/api/auth/me');
    if (!data?.data) {
      throw new Error('Unauthorized');
    }

    mobileState.user = data.data;
    applyDriverRoleFromMobileUser(data.data);
    showMobileHauls();
    await loadMobileHauls();
  } catch (error) {
    mobileState.user = null;
    showMobileLogin();
    resetRoleToDefault();
    if (!(error instanceof Error && error.message === 'Unauthorized')) {
      console.warn('mobile auth check failed', error);
    }
  }
}

function showMobileLogin() {
  setMobileViewState(mobileViewStates.LOGIN);
  if (mobileElements.loginSection) {
    mobileElements.loginSection.hidden = false;
  }
  if (mobileElements.haulsSection) {
    mobileElements.haulsSection.hidden = true;
  }
  if (mobileElements.loginForm) {
    mobileElements.loginForm.reset();
  }
  prefillSavedMobileLogin();
  setMobileLoginError('');
  setMobileError('');
  mobileState.selectedHaulId = null;
  closeMobileHaulDetails();
  mobileState.hauls = [];
  renderMobileHauls();
  if (mobileElements.loader) {
    mobileElements.loader.hidden = true;
  }
}

function showMobileHauls() {
  setMobileViewState(mobileViewStates.HAULS);
  if (mobileElements.loginSection) {
    mobileElements.loginSection.hidden = true;
  }
  if (mobileElements.haulsSection) {
    mobileElements.haulsSection.hidden = false;
  }
  if (mobileElements.userName) {
    mobileElements.userName.textContent = mobileState.user?.name ?? '';
  }
  setMobileError('');
}

function setMobileLoading(isLoading) {
  mobileState.loading = isLoading;
  if (mobileElements.loginButton) {
    mobileElements.loginButton.disabled = isLoading;
  }
}

function setMobileLoginError(message) {
  if (mobileElements.loginError) {
    mobileElements.loginError.textContent = message ?? '';
  }
}

function prefillSavedMobileLogin() {
  if (!mobileElements.loginForm) {
    return;
  }
  const savedLogin = mobileStorage.getLogin();
  if (!savedLogin) {
    return;
  }
  const loginInput = mobileElements.loginForm.querySelector('input[name="login"]');
  if (loginInput instanceof HTMLInputElement) {
    loginInput.value = savedLogin;
  }
}

function setMobileError(message) {
  if (!mobileElements.errorBox) {
    return;
  }

  if (message) {
    mobileElements.errorBox.textContent = message;
    mobileElements.errorBox.hidden = false;
  } else {
    mobileElements.errorBox.textContent = '';
    mobileElements.errorBox.hidden = true;
  }
}

function setMobileViewState(nextState) {
  if (!mobileElements.container) {
    return;
  }

  if (nextState) {
    mobileElements.container.dataset.state = nextState;
  } else {
    delete mobileElements.container.dataset.state;
  }
}

async function mobileLogin(login, password) {
  setMobileLoading(true);
  setMobileLoginError('');
  try {
    const data = await request('/api/auth/login', {
      method: 'POST',
      body: { login, password },
    });
    if (!data?.data) {
      throw new Error('Не удалось получить данные пользователя.');
    }

    mobileState.user = data.data;
    applyDriverRoleFromMobileUser(data.data);
    mobileStorage.saveLogin(login);
    showMobileHauls();
    await loadMobileHauls();
  } catch (error) {
    handleApiError(error, { target: 'mobile-login', message: 'Не удалось авторизоваться.' });
  } finally {
    setMobileLoading(false);
  }
}

async function mobileLogout() {
  try {
    await request('/api/auth/logout', { method: 'POST' });
  } catch (error) {
    console.warn('Ошибка при выходе', error);
  } finally {
    mobileState.user = null;
    mobileState.selectedHaulId = null;
    closeMobileHaulDetails();
    showMobileLogin();
    resetRoleToDefault();
  }
}

async function loadMobileHauls() {
  if (!mobileState.user) {
    await mobileCheckAuth();
    return;
  }

  if (mobileElements.loader) {
    mobileElements.loader.hidden = false;
  }
  setMobileError('');
  if (mobileElements.empty) {
    mobileElements.empty.hidden = true;
  }

  try {
    const data = await request('/api/mobile/hauls');
    mobileState.hauls = Array.isArray(data?.data) ? data.data : [];
    renderMobileHauls();
  } catch (error) {
    if (error instanceof Error && error.message === 'Unauthorized') {
      mobileState.user = null;
      showMobileLogin();
      return;
    }

    handleApiError(error, { target: 'mobile', message: 'Не удалось загрузить рейсы.' });
    mobileState.hauls = [];
    renderMobileHauls();
  } finally {
    if (mobileElements.loader) {
      mobileElements.loader.hidden = true;
    }
  }
}

function renderMobileHauls() {
  const list = mobileElements.list;
  if (!list) {
    return;
  }

  list.innerHTML = '';
  const items = Array.isArray(mobileState.hauls) ? mobileState.hauls : [];

  if (items.length === 0) {
    if (mobileElements.empty) {
      mobileElements.empty.hidden = false;
    }
    return;
  }

  if (mobileElements.empty) {
    mobileElements.empty.hidden = true;
  }

  for (const haul of items) {
    const card = buildMobileHaulCard(haul);
    if (card) {
      list.appendChild(card);
    }
  }
}

function buildMobileHaulCard(haul) {
  if (!haul || typeof haul !== 'object') {
    return null;
  }

  const item = document.createElement('li');
  item.className = 'mobile-haul';
  item.tabIndex = 0;
  item.setAttribute('role', 'button');

  const route = document.createElement('div');
  route.className = 'mobile-haul__route';
  route.append(
    buildRoutePoint('Погрузка', haul?.load?.address_text),
    buildRoutePoint(
      'Выгрузка',
      haul?.unload?.address_text,
      haul?.unload?.acceptance_time ? `Время приёмки: ${haul.unload.acceptance_time}` : null
    )
  );
  item.appendChild(route);

  const meta = document.createElement('div');
  meta.className = 'mobile-haul__meta';

  if (haul?.material_id) {
    meta.appendChild(createMetaBadge(`Материал: ${haul.material_id}`));
  }

  const statusBadge = buildStatusBadge(haul.status);
  if (statusBadge) {
    meta.appendChild(statusBadge);
  }

  const updatedLabel = formatDateTimeLabel(haul?.updated_at || haul?.created_at);
  if (updatedLabel) {
    meta.appendChild(createMetaBadge(`Обновлено: ${updatedLabel}`));
  }

  if (meta.childElementCount > 0) {
    item.appendChild(meta);
  }

  const openDetails = () => {
    openMobileHaulDetails(haul);
  };

  item.addEventListener('click', openDetails);
  item.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openDetails();
    }
  });

  return item;
}

function createMetaBadge(text) {
  const span = document.createElement('span');
  span.textContent = text;
  return span;
}

function buildRoutePoint(label, address, extra = null) {
  const paragraph = document.createElement('p');
  paragraph.className = 'mobile-haul__point';

  const caption = document.createElement('span');
  caption.textContent = label;
  paragraph.appendChild(caption);

  const addressLine = document.createElement('strong');
  addressLine.textContent = address || '—';
  paragraph.appendChild(addressLine);

  if (extra) {
    const extraLine = document.createElement('small');
    extraLine.textContent = extra;
    paragraph.appendChild(extraLine);
  }

  return paragraph;
}

function buildStatusBadge(status) {
  if (!isDriverStatusVisible(status)) {
    return null;
  }

  const badge = document.createElement('span');
  badge.className = 'mobile-haul__status-badge';
  badge.textContent = getStatusLabel(status);
  return badge;
}


function formatMobileHaulTitle(haul) {
  const sequence = formatSequence(haul);
  if (sequence && sequence !== '-') {
    return `Рейс №${sequence}`;
  }
  return 'Рейс';
}

function formatMobileHaulSubtitle(haul) {
  const dealId = Number.isFinite(haul?.deal_id) ? Number(haul.deal_id) : null;
  const loadPoint = haul?.load?.address_text ? String(haul.load.address_text) : 'Без адреса';
  const unloadPoint = haul?.unload?.address_text ? String(haul.unload.address_text) : null;

  const route = unloadPoint ? `${loadPoint} → ${unloadPoint}` : loadPoint;
  return dealId ? `Сделка #${dealId} · ${route}` : route;
}

function openMobileHaulDetails(haulOrId) {
  if (!mobileElements.dialog) {
    return;
  }

  const haul = typeof haulOrId === 'object' && haulOrId !== null
    ? haulOrId
    : mobileState.hauls.find((item) => item.id === haulOrId);

  if (!haul) {
    console.warn('Рейс не найден для детального просмотра');
    return;
  }

  mobileState.selectedHaulId = haul.id ?? null;
  setMobileDialogError('');
  setMobileDialogLoading(false);
  renderMobileHaulDetails(haul);
  mobileElements.dialog.hidden = false;
  mobileElements.dialog.setAttribute('aria-hidden', 'false');
  document.body.classList.add('mobile-dialog-open');
}

function closeMobileHaulDetails() {
  if (!mobileElements.dialog || mobileElements.dialog.hidden) {
    return;
  }

  mobileElements.dialog.hidden = true;
  mobileElements.dialog.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('mobile-dialog-open');
  if (mobileElements.dialogBody) {
    mobileElements.dialogBody.innerHTML = '';
  }
  mobileElements.dialogVolumeInput = null;
  setMobileDialogError('');
  setMobileDialogLoading(false);
  mobileState.selectedHaulId = null;
}

function handleMobileDialogKeydown(event) {
  if (event.key !== 'Escape') {
    return;
  }

  if (mobileElements.dialog && mobileElements.dialog.getAttribute('aria-hidden') === 'false') {
    event.preventDefault();
    closeMobileHaulDetails();
  }
}

function renderMobileHaulDetails(haul) {
  if (!mobileElements.dialogBody) {
    return;
  }

  const latest = mobileState.hauls.find((item) => item.id === haul?.id);
  const data = latest ?? haul;
  mobileElements.dialogBody.innerHTML = '';
  mobileElements.dialogVolumeInput = null;
  setMobileDialogError('');

  if (mobileElements.dialogTitle) {
    mobileElements.dialogTitle.textContent = formatMobileHaulTitle(data);
  }

  if (mobileElements.dialogMeta) {
    mobileElements.dialogMeta.textContent = buildMobileHaulMetaLine(data);
  }

  const infoSection = buildMobileSummarySection(data);
  if (infoSection) {
    mobileElements.dialogBody.appendChild(infoSection);
  }

  const loadActions = buildLoadActionSection(data);
  if (loadActions) {
    mobileElements.dialogBody.appendChild(loadActions);
  }

  const loadSection = buildAddressSection('Адрес погрузки', data?.load, {
    showMapLink: true,
    includeContact: false,
    sectionClass: 'mobile-haul-dialog__section',
    photoPlaceholderText: 'Фото документов с погрузки появятся здесь.',
  });
  if (loadSection) {
    mobileElements.dialogBody.appendChild(loadSection);
  }

  const unloadActions = buildUnloadActionSection(data);
  if (unloadActions) {
    mobileElements.dialogBody.appendChild(unloadActions);
  }

  const unloadSection = buildAddressSection('Адрес выгрузки', data?.unload, {
    includeContact: true,
    sectionClass: 'mobile-haul-dialog__section',
    photoPlaceholderText: 'Фото документов с выгрузки появятся здесь.',
  });
  if (unloadSection) {
    mobileElements.dialogBody.appendChild(unloadSection);
  }

  const historySection = buildMobileStatusHistorySection(data);
  if (historySection) {
    mobileElements.dialogBody.appendChild(historySection);
  }
}

function buildMobileHaulMetaLine(haul) {
  const parts = [];
  if (isDriverStatusVisible(haul?.status)) {
    const statusLabel = getStatusLabel(haul?.status);
    parts.push(statusLabel);
  }
  if (haul?.deal_id) {
    parts.push(`Сделка #${haul.deal_id}`);
  }
  const updatedLabel = formatDateTimeLabel(haul?.updated_at || haul?.created_at);
  if (updatedLabel) {
    parts.push(`Обновлено: ${updatedLabel}`);
  }
  return parts.join(' · ');
}

function buildMobileSummarySection(haul) {
  if (!haul) {
    return null;
  }

  const section = document.createElement('div');
  section.className = 'mobile-haul-dialog__section';

  const heading = document.createElement('h3');
  heading.textContent = 'Информация';
  section.appendChild(heading);

  const list = document.createElement('ul');
  list.className = 'mobile-haul-dialog__list';

  if (isDriverStatusVisible(haul?.status)) {
    list.appendChild(createSummaryRow('Статус', getStatusLabel(haul.status)));
  }
  list.appendChild(createSummaryRow('Сделка', haul.deal_id ? `#${haul.deal_id}` : '—'));
  list.appendChild(createSummaryRow('Самосвал', haul.truck_id || '—'));
  list.appendChild(createSummaryRow('Материал', haul.material_id || '—'));

  const volume = formatVolume(haul?.load?.volume);
  list.appendChild(createSummaryRow('План, м³', volume || '—'));

  const actualVolume = formatVolume(haul?.load?.actual_volume);
  list.appendChild(createSummaryRow('Факт, м³', actualVolume || '—'));

  section.appendChild(list);

  if (haul?.general_notes) {
    const notes = document.createElement('p');
    notes.className = 'mobile-status-note';
    notes.textContent = haul.general_notes;
    section.appendChild(notes);
  }

  return section;
}

function createSummaryRow(label, value) {
  const item = document.createElement('li');
  item.className = 'mobile-haul-dialog__list-item';

  const name = document.createElement('span');
  name.className = 'mobile-haul-dialog__list-label';
  name.textContent = label;

  const val = document.createElement('span');
  val.textContent = value ?? '—';

  item.append(name, val);
  return item;
}

function buildAddressSection(title, block, options = {}) {
  if (!block || typeof block !== 'object') {
    return null;
  }

  const {
    includeContact = false,
    showVolume = true,
    showMapLink = true,
    sectionClass = 'mobile-haul__section',
    photoPlaceholderText = '',
  } = options;

  const hasContent = Boolean(
    block.address_text ||
      block.address_url ||
      (showVolume && block.volume !== undefined && block.volume !== null && block.volume !== '') ||
      (includeContact && (block.contact_name || block.contact_phone))
  );

  if (!hasContent) {
    return null;
  }

  const section = document.createElement('div');
  section.className = sectionClass;

  const heading = document.createElement('h3');
  heading.textContent = title;
  section.appendChild(heading);

  if (block.address_text) {
    const address = document.createElement('p');
    address.className = 'mobile-haul__address';
    address.textContent = block.address_text;
    section.appendChild(address);
  }

  if (showMapLink && block.address_url) {
    const link = document.createElement('a');
    link.className = 'mobile-haul__link';
    link.href = block.address_url;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Открыть карту';
    section.appendChild(link);
  }

  if (showVolume && block.volume !== undefined && block.volume !== null && block.volume !== '') {
    const volumeValue = formatVolume(block.volume);
    if (volumeValue) {
      const volume = document.createElement('p');
      volume.className = 'mobile-haul__note';
      volume.textContent = `Объём: ${volumeValue} м³`;
      section.appendChild(volume);
    }
  }

  if (includeContact) {
    const contactParts = [];
    if (block.contact_name) {
      contactParts.push(String(block.contact_name));
    }
    if (block.contact_phone) {
      contactParts.push(String(block.contact_phone));
    }

    if (contactParts.length > 0) {
      const contact = document.createElement('p');
      contact.className = 'mobile-haul__note';
      contact.textContent = `Контакт: ${contactParts.join(', ')}`;
      section.appendChild(contact);
    }
  }

  if (photoPlaceholderText) {
    section.appendChild(createPhotoPlaceholder(photoPlaceholderText));
  }

  return section;
}

function buildLoadActionSection(haul) {
  if (!haul) {
    return null;
  }

  const section = document.createElement('div');
  section.className = 'mobile-haul-dialog__section mobile-status-controls';

  const heading = document.createElement('h3');
  heading.textContent = 'Загрузка';
  section.appendChild(heading);

  const note = document.createElement('p');
  note.className = 'mobile-status-note';
  note.textContent = 'Введите фактический объём после взвешивания и подтвердите загрузку.';
  section.appendChild(note);

  const volumeInput = document.createElement('input');
  volumeInput.type = 'number';
  volumeInput.step = '0.01';
  volumeInput.min = '0';
  volumeInput.placeholder = 'Фактический объём, м³';
  if (haul?.load?.actual_volume !== undefined && haul?.load?.actual_volume !== null) {
    volumeInput.value = String(haul.load.actual_volume);
  }
  section.appendChild(volumeInput);
  mobileElements.dialogVolumeInput = volumeInput;

  section.appendChild(createPhotoPlaceholder('Фото документов с погрузки появятся здесь.'));

  const buttons = document.createElement('div');
  buttons.className = 'mobile-status-buttons';
  const button = buildLoadStatusButton(haul);
  if (button) {
    buttons.appendChild(button);
  }
  section.appendChild(buttons);

  return section;
}

function buildUnloadActionSection(haul) {
  if (!haul) {
    return null;
  }

  const section = document.createElement('div');
  section.className = 'mobile-haul-dialog__section mobile-status-controls';

  const heading = document.createElement('h3');
  heading.textContent = 'Выгрузка';
  section.appendChild(heading);

  const note = document.createElement('p');
  note.className = 'mobile-status-note';
  note.textContent = 'После сдачи груза подтвердите выгрузку.';
  section.appendChild(note);

  section.appendChild(createPhotoPlaceholder('Фото документов с выгрузки появятся здесь.'));

  const buttons = document.createElement('div');
  buttons.className = 'mobile-status-buttons';
  const button = buildUnloadStatusButton(haul);
  if (button) {
    buttons.appendChild(button);
  }
  section.appendChild(buttons);

  return section;
}

function buildMobileStatusHistorySection(haul) {
  const history = Array.isArray(haul?.status_history) ? haul.status_history : [];
  if (history.length === 0) {
    return null;
  }

  const section = document.createElement('div');
  section.className = 'mobile-haul-dialog__section';

  const heading = document.createElement('h3');
  heading.textContent = 'История статусов';
  section.appendChild(heading);

  const list = document.createElement('ul');
  list.className = 'mobile-haul-dialog__list';

  for (const event of history) {
    const label = getStatusLabel(event.status);
    const dateLabel = formatDateTimeLabel(event.changed_at) || '—';
    list.appendChild(createSummaryRow(label, dateLabel));
  }

  section.appendChild(list);
  return section;
}

function buildLoadStatusButton(haul) {
  const value = getStatusValue(haul?.status);
  if (value === null) {
    return null;
  }

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'mobile-status-button';
  button.textContent = 'Загрузился';

  const isLoaded = value >= 2;
  const isLocked = value >= 3;

  button.classList.add(isLoaded ? 'mobile-status-button--active' : 'mobile-status-button--ghost');
  button.dataset.permanentDisabled = isLocked ? 'true' : 'false';
  button.disabled = isLocked;

  if (!isLocked) {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const nextStatus = value === 2 ? 1 : 2;
      void handleLoadStatusClick(haul.id, nextStatus);
    });
  }

  return button;
}

function buildUnloadStatusButton(haul) {
  const value = getStatusValue(haul?.status);
  if (value === null) {
    return null;
  }

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'mobile-status-button';
  button.textContent = 'Выгрузился';

  const isUnloaded = value >= 3;
  const unavailable = value < 2 || isUnloaded;

  button.classList.add(isUnloaded ? 'mobile-status-button--active' : 'mobile-status-button--ghost');
  button.dataset.permanentDisabled = unavailable ? 'true' : 'false';
  button.disabled = unavailable;

  if (!unavailable) {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      void handleUnloadStatusClick(haul.id);
    });
  }

  return button;
}

function createPhotoPlaceholder(text) {
  const box = document.createElement('div');
  box.className = 'mobile-photo-placeholder';
  box.textContent = text;
  return box;
}

function getCurrentActualVolumeValue() {
  const input = mobileElements.dialogVolumeInput;
  if (!(input instanceof HTMLInputElement)) {
    return null;
  }
  const raw = input.value.trim();
  return raw === '' ? null : raw;
}

async function handleLoadStatusClick(haulId, nextStatus) {
  if (!haulId) {
    return;
  }

  const requiresVolume = nextStatus === 2;
  const actualVolume = getCurrentActualVolumeValue();

  if (requiresVolume && (actualVolume === null || actualVolume === '')) {
    setMobileDialogError('Укажите фактический объём после взвешивания.');
    return;
  }

  setMobileDialogError('');
  await mobileUpdateStatus(haulId, nextStatus, { load_actual_volume: actualVolume });
}

async function handleUnloadStatusClick(haulId) {
  if (!haulId) {
    return;
  }
  setMobileDialogError('');
  await mobileUpdateStatus(haulId, 3);
}

async function mobileUpdateStatus(haulId, status, extra = {}) {
  try {
    setMobileDialogLoading(true);
    const data = await request(`/api/mobile/hauls/${haulId}/status`, {
      method: 'POST',
      body: { status, ...extra },
    });
    if (data?.data) {
      applyMobileHaulUpdate(data.data);
      renderMobileHauls();
      if (mobileState.selectedHaulId === data.data.id) {
        renderMobileHaulDetails(data.data);
      }
    }
  } catch (error) {
    handleApiError(error, { target: 'mobile-dialog', message: 'Не удалось обновить статус.' });
  } finally {
    setMobileDialogLoading(false);
  }
}

function applyMobileHaulUpdate(updated) {
  if (!updated || typeof updated !== 'object') {
    return;
  }

  const index = mobileState.hauls.findIndex((item) => item.id === updated.id);
  if (index >= 0) {
    mobileState.hauls[index] = updated;
  } else {
    mobileState.hauls.unshift(updated);
  }
}

function setMobileDialogError(message) {
  if (!mobileElements.dialogError) {
    return;
  }
  mobileElements.dialogError.textContent = message || '';
  mobileElements.dialogError.hidden = !message;
}

function setMobileDialogLoading(isLoading) {
  const buttons = mobileElements.dialogBody?.querySelectorAll('.mobile-status-button');
  if (!buttons) {
    return;
  }
  buttons.forEach((button) => {
    const permanent = button.dataset.permanentDisabled === 'true';
    if (isLoading) {
      button.disabled = true;
    } else {
      button.disabled = permanent;
    }
  });
}

function formatDateTimeLabel(value) {
  if (!value) {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatVolume(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) {
    return '';
  }

  return numeric.toLocaleString('ru-RU', {
    maximumFractionDigits: 2,
    minimumFractionDigits: numeric % 1 === 0 ? 0 : 2,
  });
}

function formatDistance(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) {
    return '';
  }

  return numeric.toLocaleString('ru-RU', {
    maximumFractionDigits: 1,
    minimumFractionDigits: numeric % 1 === 0 ? 0 : 1,
  });
}

function getStatusValue(status) {
  if (status && typeof status === 'object' && 'value' in status) {
    const numeric = Number(status.value);
    return Number.isFinite(numeric) ? numeric : null;
  }
  if (status === undefined || status === null) {
    return null;
  }
  const numeric = Number(status);
  return Number.isFinite(numeric) ? numeric : null;
}

function getStatusLabel(status) {
  if (status && typeof status === 'object' && typeof status.label === 'string') {
    return status.label;
  }
  const value = getStatusValue(status);
  if (value === null) {
    return 'Неизвестно';
  }
  return mobileStatusLabels[value] ?? 'Неизвестно';
}

function isDriverStatusVisible(status) {
  const value = getStatusValue(status);
  if (value === null) {
    return false;
  }
  return driverVisibleStatuses.has(value);
}

function detectDarkMode() {
  if (state.embedded) {
    document.body.classList.remove('dark');
    return;
  }

  if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    document.body.classList.add('dark');
  }
}

function configureEmbedding() {
  if (state.embedded) {
    document.body.classList.add('embedded');
  }

  window.addEventListener('resize', () => scheduleFitWindow());
  if (window.matchMedia) {
    const portrait = window.matchMedia('(orientation: portrait)');
    if (typeof portrait.addEventListener === 'function') {
      portrait.addEventListener('change', () => scheduleFitWindow());
    } else if (typeof portrait.addListener === 'function') {
      portrait.addListener(() => scheduleFitWindow());
    }
  }
}

function attachEventHandlers() {
  elements.loadButton?.addEventListener('click', () => {
    const dealId = Number(elements.dealInput.value);
    if (!Number.isFinite(dealId) || dealId <= 0) {
      showGlobalError('Введите корректный ID сделки');
      return;
    }
    clearGlobalError();
    setDealId(dealId);
    navigateTo(views.LIST);
    loadHauls();
  });

  elements.floatingCreate?.addEventListener('click', handleCreateRequest);

  elements.closeEditor?.addEventListener('click', () => navigateTo(views.LIST));
  elements.cancelEditor?.addEventListener('click', () => navigateTo(views.LIST));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.view !== views.LIST) {
      navigateTo(views.LIST);
    }
  });

  elements.haulForm?.addEventListener('submit', onSubmitForm);

  elements.haulsList?.addEventListener('click', (event) => {
    const copyButton = event.target.closest('[data-action="copy"]');
    if (copyButton) {
      const haulId = copyButton.getAttribute('data-haul-id');
      if (haulId) {
        void handleCopyRequest(haulId);
      }
      return;
    }

    const editButton = event.target.closest('[data-action="edit"]');
    if (editButton) {
      const haulId = editButton.getAttribute('data-haul-id');
      if (haulId) {
        navigateTo(views.EDIT, haulId);
      }
      return;
    }

    const deleteButton = event.target.closest('[data-action="delete"]');
    if (deleteButton) {
      const haulId = deleteButton.getAttribute('data-haul-id');
      if (haulId) {
        deleteHaul(haulId);
      }
    }
  });

  elements.carrierSelect?.addEventListener('change', () => {
    syncUnloadFromCompany();
  });
}

function initRouter() {
  window.addEventListener('hashchange', () => {
    if (pendingHashSkips > 0) {
      pendingHashSkips -= 1;
      return;
    }
    handleHashChange().catch((error) => console.error('Routing error', error));
  });

  if (!window.location.hash) {
    window.location.replace(`#${views.LIST}`);
  }

  void handleHashChange();
}

async function handleHashChange() {
  const { view, haulId } = parseHash(window.location.hash);
  await applyView(view, haulId, { updateHash: false });
}

function parseHash(hash) {
  const clean = (hash || '').replace(/^#/, '');
  if (!clean) {
    return { view: views.LIST, haulId: null };
  }

  const [view, id] = clean.split('/');
  if (view === views.CREATE) {
    return { view: views.CREATE, haulId: null };
  }

  if (view === views.EDIT && id) {
    return { view: views.EDIT, haulId: id };
  }

  return { view: views.LIST, haulId: null };
}

function navigateTo(view, haulId = null, extra = {}) {
  const targetHash = buildHash(view, haulId);
  if (window.location.hash !== targetHash) {
    pendingHashSkips += 1;
    window.location.hash = targetHash;
  }
  return applyView(view, haulId, { updateHash: false, template: extra.template });
}

function buildHash(view, haulId) {
  if (view === views.CREATE) {
    return `#${views.CREATE}`;
  }
  if (view === views.EDIT && haulId) {
    return `#${views.EDIT}/${haulId}`;
  }
  return `#${views.LIST}`;
}

async function applyView(view, haulId, options = {}) {
  if (options.updateHash !== false) {
    const targetHash = buildHash(view, haulId);
    if (window.location.hash !== targetHash) {
      pendingHashSkips += 1;
      window.location.hash = targetHash;
    }
  }

  if (options.template) {
    state.formTemplate = options.template;
  }

  state.view = view;
  state.currentHaulId = view === views.EDIT ? haulId : null;
  state.currentHaulSnapshot = null;
  if (view !== views.CREATE) {
    state.formTemplate = null;
  } else if (!state.formTemplate && state.currentHaulId && state.currentHaulSnapshot) {
    state.formTemplate = cloneHaulTemplate(state.currentHaulSnapshot);
  }
  elements.app?.setAttribute('data-view', view);
  updatePanelsVisibility(view);

  if (view === views.LIST) {
    renderList();
    scheduleFitWindow();
    return;
  }

  if (!state.dealId) {
    showGlobalError('Сначала выберите сделку.');
    navigateTo(views.LIST);
    return;
  }

  if (view === views.CREATE) {
    await ensureReferenceDataLoaded();
    prepareCreateForm();
    if (state.formTemplate) {
      applyTemplateToForm(state.formTemplate, { includeStatus: false });
      state.formTemplate = null;
    }
    const meta = buildEditorMeta({
      mode: 'create',
      sequence: estimateNextSequence(),
    });
    openEditor(meta);
    return;
  }

  if (view === views.EDIT && haulId) {
    const haul = await resolveHaul(haulId, { forceReload: true });
    if (!haul) {
      handleApiError(null, { message: 'Рейс не найден или был удалён.' });
      navigateTo(views.LIST);
      return;
    }

    await ensureReferenceDataLoaded();
    state.currentHaulSnapshot = haul;
    prepareEditForm(haul);
    const meta = buildEditorMeta({
      mode: 'edit',
      sequence: formatSequence(haul),
    });
    openEditor(meta);
  }
}

function setDealId(id) {
  state.dealId = id;
  if (elements.dealInput) {
    elements.dealInput.value = id;
  }
  updateDealMeta();
  void refreshDealMeta();
}

function updateDealMeta() {
  const dealName = typeof state.dealMeta?.title === 'string' && state.dealMeta.title.trim() !== ''
    ? state.dealMeta.title.trim()
    : null;

  if (elements.dealTitle) {
    if (dealName) {
      elements.dealTitle.textContent = dealName;
    } else if (state.dealId) {
      elements.dealTitle.textContent = `Сделка #${state.dealId}`;
    } else {
      elements.dealTitle.textContent = 'Сделка не выбрана';
    }
  }

  if (elements.dealIdLabel) {
    if (state.dealId) {
      elements.dealIdLabel.textContent = `ID: ${state.dealId}`;
      elements.dealIdLabel.hidden = false;
    } else {
      elements.dealIdLabel.textContent = '';
      elements.dealIdLabel.hidden = true;
    }
  }

  if (!elements.dealSubtitle) {
    return;
  }

  if (!state.dealId) {
    elements.dealSubtitle.textContent = 'Загрузите существующие рейсы или создайте новый.';
    return;
  }

  const count = state.hauls.length;
  if (elements.dealSubtitle) {
    if (state.dealMeta?.company?.title) {
      elements.dealSubtitle.textContent = `Клиент: ${state.dealMeta.company.title}`;
    } else if (count > 0) {
      elements.dealSubtitle.textContent = `Всего рейсов: ${count}`;
    } else {
      elements.dealSubtitle.textContent = '';
    }
  }
}

async function refreshDealMeta() {
  if (!state.dealId) {
    state.dealMeta = null;
    updateDealMeta();
    syncUnloadToCompany();
    return;
  }

  try {
    const response = await request(`/api/deals/${state.dealId}`);
    state.dealMeta = response?.data ?? null;
    if (state.dealMeta?.title) {
      applyDealTitle(state.dealMeta.title, { force: true });
    }
  } catch (error) {
    console.warn('Не удалось получить данные сделки', error);
  }

  updateDealMeta();
  syncUnloadToCompany();
}

async function detectDealId() {
  if (state.dealId) {
    return true;
  }

  const bootstrap = window.B24_INSTALL_PAYLOAD || null;
  const bootstrapPayload = bootstrap?.payload || null;
  const bootstrapQuery = bootstrap?.get || bootstrap?.query || null;
  const bootstrapRequest = bootstrap?.request || null;

  const bootstrapOptionsRaw = extractPlacementOptionsRaw(bootstrapPayload);
  if (bootstrapOptionsRaw) {
    const options = parsePlacementOptions(bootstrapOptionsRaw);
    applyDealTitle(extractDealTitleFromObject(options));
    const idFromOptions = extractDealIdFromObject(options);
    if (idFromOptions) {
      setDealId(idFromOptions);
      scheduleBx24DealLookup();
      return true;
    }
  }

  const payloadTitle = extractDealTitleFromObject(bootstrapPayload);
  applyDealTitle(payloadTitle);
  const idFromPayload = extractDealIdFromObject(bootstrapPayload);
  if (idFromPayload) {
    setDealId(idFromPayload);
    if (payloadTitle) {
      applyDealTitle(payloadTitle, { force: true });
    }
    scheduleBx24DealLookup();
    return true;
  }

  const queryTitle = extractDealTitleFromObject(bootstrapQuery);
  applyDealTitle(queryTitle);
  const idFromQuery = extractDealIdFromObject(bootstrapQuery);
  if (idFromQuery) {
    setDealId(idFromQuery);
    if (queryTitle) {
      applyDealTitle(queryTitle, { force: true });
    }
    scheduleBx24DealLookup();
    return true;
  }

  const idFromRequest = extractDealIdFromObject(bootstrapRequest);
  if (idFromRequest) {
    setDealId(idFromRequest);
    applyDealTitle(extractDealTitleFromObject(bootstrapRequest));
    scheduleBx24DealLookup();
    return true;
  }

  const requestOptionsRaw = extractPlacementOptionsRaw(bootstrapRequest);
  if (requestOptionsRaw) {
    const requestOptions = parsePlacementOptions(requestOptionsRaw);
    applyDealTitle(extractDealTitleFromObject(requestOptions));
    const idFromRequestOptions = extractDealIdFromObject(requestOptions);
    if (idFromRequestOptions) {
      setDealId(idFromRequestOptions);
      scheduleBx24DealLookup();
      return true;
    }
  }

  const searchParams = new URLSearchParams(window.location.search);
  const placementOptionsRaw =
    searchParams.get('PLACEMENT_OPTIONS') ||
    searchParams.get('placement_options');

  if (placementOptionsRaw) {
    const parsedOptions = parsePlacementOptions(placementOptionsRaw);
    applyDealTitle(extractDealTitleFromObject(parsedOptions));
    const optionDealId = extractDealIdFromObject(parsedOptions);
    if (optionDealId) {
      setDealId(optionDealId);
      return true;
    }
  }

  const candidate = searchParams.get('dealId') || searchParams.get('deal_id');
  const titleParam =
    searchParams.get('dealTitle') ||
    searchParams.get('deal_title') ||
    searchParams.get('DEAL_TITLE');
  if (titleParam) {
    applyDealTitle(titleParam);
  }

  if (candidate) {
    const numericId = Number(candidate);
    if (Number.isFinite(numericId)) {
      setDealId(numericId);
      if (titleParam) {
        applyDealTitle(titleParam, { force: true });
      }
      scheduleBx24DealLookup();
      return true;
    }

    const digits = candidate.replace(/\D+/g, '');
    const fallbackId = Number(digits);
    if (Number.isFinite(fallbackId) && digits.length > 0) {
      setDealId(fallbackId);
      if (titleParam) {
        applyDealTitle(titleParam, { force: true });
      }
      scheduleBx24DealLookup();
      return true;
    }
  }

  const referrerId = extractDealIdFromReferrer();
  if (referrerId) {
    setDealId(referrerId);
    scheduleBx24DealLookup();
    return true;
  }

  if (!state.embedded) {
    return Boolean(state.dealId);
  }

  if (!state.dealId) {
    await fetchDealFromBx24();
  } else {
    scheduleBx24DealLookup();
  }

  return Boolean(state.dealId);
}

async function loadReferenceData() {
  try {
    const [trucks, materials, suppliers, carriers] = await Promise.all([
      request('/api/trucks'),
      request('/api/materials'),
      loadCompanyDirectory('supplier'),
      loadCompanyDirectory('carrier'),
    ]);

    let driversResponse = null;
    try {
      driversResponse = await request('/api/drivers');
    } catch (error) {
      console.warn('Не удалось загрузить список водителей', error);
    }

    state.trucks = Array.isArray(trucks?.data) ? trucks.data : [];
    state.materials = Array.isArray(materials?.data) ? materials.data : [];
    state.suppliers = suppliers;
    state.carriers = carriers;

    const driverData = driversResponse?.data ?? driversResponse ?? [];
    state.drivers = Array.isArray(driverData) ? driverData : Object.values(driverData);
    renderReferenceSelects();
    syncUnloadFromCompany();
    renderList();
  } catch (error) {
    console.error('Не удалось загрузить справочники', error);
    throw error;
  }
}

async function ensureReferenceDataLoaded() {
  if (!referenceDataPromise) {
    referenceDataPromise = Promise.resolve();
  }
  try {
    await referenceDataPromise;
  } catch (error) {
    console.warn('Справочники недоступны, продолжим без них', error);
  }
}

function scheduleBx24DealLookup() {
  if (!state.embedded || typeof window === 'undefined') {
    return;
  }
  if (bx24DealLookupPromise) {
    return;
  }
  bx24DealLookupPromise = fetchDealFromBx24().finally(() => {
    bx24DealLookupPromise = null;
  });
}

async function fetchDealFromBx24() {
  const bx24 = await waitForBx24();
  if (!bx24) {
    console.warn('BX24 API не готова — ID сделки не определён автоматически');
    return;
  }

  await new Promise((resolve) => {
    try {
      bx24.init(() => {
        let finished = false;
        const finish = () => {
          if (!finished) {
            finished = true;
            resolve();
          }
        };

        const tryApplyDealId = (possible) => {
          const numericId = Number(possible);
          if (Number.isFinite(numericId)) {
            setDealId(numericId);
            finish();
          }
        };

        let placementInfo = null;
        let placementParams = null;
        try {
          placementInfo = typeof bx24.placement?.info === 'function'
            ? bx24.placement.info()
            : null;
          applyDealTitle(extractDealTitleFromObject(placementInfo));
          const placementId = placementInfo?.entity_id
            ?? placementInfo?.deal_id
            ?? placementInfo?.ID
            ?? placementInfo?.ENTITY_ID;
          if (placementId) {
            tryApplyDealId(placementId);
          }
        } catch (infoError) {
          console.warn('BX24 placement info недоступна', infoError);
        }

        try {
          placementParams = typeof bx24.placement?.getParams === 'function'
            ? bx24.placement.getParams()
            : null;
          applyDealTitle(extractDealTitleFromObject(placementParams));
          const paramsId = placementParams?.deal_id
            ?? placementParams?.dealId
            ?? placementParams?.ID
            ?? placementParams?.entity_id
            ?? placementParams?.ENTITY_ID;
          if (paramsId) {
            tryApplyDealId(paramsId);
          }
        } catch (paramsError) {
          console.warn('BX24 placement params недоступны', paramsError);
        }

        if (typeof bx24.getPageParams === 'function') {
          bx24.getPageParams((params) => {
            const possible = params?.deal_id || params?.ID || params?.entity_id || params?.ENTITY_ID;
            applyDealTitle(extractDealTitleFromObject(params));
            tryApplyDealId(possible);
            bx24.fitWindow?.();
            finish();
          });
        } else if (typeof bx24.callMethod === 'function') {
          const dealId = placementInfo?.entity_id
            ?? placementInfo?.deal_id
            ?? placementInfo?.ID
            ?? placementInfo?.ENTITY_ID
            ?? placementParams?.deal_id
            ?? placementParams?.dealId
            ?? placementParams?.ID
            ?? placementParams?.entity_id
            ?? placementParams?.ENTITY_ID
            ?? state.dealId;

          if (dealId) {
            bx24.callMethod('crm.deal.get', { id: dealId }, (result) => {
              if (result?.data?.ID) {
                tryApplyDealId(result.data.ID);
                if (typeof result.data.TITLE === 'string') {
                  applyDealTitle(result.data.TITLE);
                }
              }
              finish();
            });
          } else {
            finish();
          }
        } else {
          finish();
        }

        setTimeout(finish, 2000);
      });
    } catch (error) {
      console.warn('BX24 init/getPageParams failed', error);
      resolve();
    }
  });
}

async function loadCompanyDirectory(type) {
  try {
    const response = await request(`/api/crm/companies?type=${encodeURIComponent(type)}`);
    const list = Array.isArray(response?.data) ? response.data : [];
    return list.map((item) => ({
      id: item.id,
      title: item.title || `#${item.id}`,
      phone: item.phone ?? null,
    }));
  } catch (error) {
    console.warn(`Не удалось загрузить компании (${type})`, error);
    return [];
  }
}

async function resolveActorFromBitrix() {
  if (!state.embedded) {
    return;
  }

  try {
    const data = await callBx24Method('user.current');
    if (!data) {
      return;
    }
    const userId = data.ID ?? data.id ?? null;
    const lastName = typeof data.LAST_NAME === 'string' ? data.LAST_NAME.trim() : '';
    const firstName = typeof data.NAME === 'string' ? data.NAME.trim() : '';
    const secondName = typeof data.SECOND_NAME === 'string' ? data.SECOND_NAME.trim() : '';
    const nameParts = [lastName, firstName, secondName].filter(Boolean);

    const context = {
      id: userId ? Number(userId) : null,
      name: nameParts.length ? nameParts.join(' ') : (data.EMAIL ?? null),
    };
    const role = deriveRoleFromBitrixUser(data);
    applyRole(role, context);
  } catch (error) {
    console.warn('Не удалось определить пользователя Bitrix24', error);
  }
}

function renderReferenceSelects() {
  renderSelect(elements.driverSelect, state.drivers, {
    placeholder: 'Выберите водителя',
    allowEmpty: false,
    getLabel: (driver) => {
      const parts = [driver.name];
      if (driver.position) {
        parts.push(driver.position);
      }
      return parts.join(' · ');
    },
  });

  renderSelect(elements.truckSelect, state.trucks, {
    placeholder: 'Не выбрано',
    allowEmpty: false,
    getLabel: (truck) => truck.license_plate || truck.name || truck.id,
  });

  renderSelect(elements.materialSelect, state.materials, {
    placeholder: 'Не выбрано',
    allowEmpty: false,
    getLabel: (material) => material.name || material.id,
  });

  renderCompanySelect(elements.supplierSelect, state.suppliers, 'Выберите поставщика');
  renderCompanySelect(elements.carrierSelect, state.carriers, 'Выберите перевозчика');
}

function syncUnloadFromCompany() {
  if (!elements.unloadFromInput) {
    return;
  }

  const carrierId = elements.carrierSelect?.value ?? '';
  elements.unloadFromInput.value = carrierId || '';
  if (elements.unloadFromDisplay) {
    const label = lookupCompanyName(state.carriers, carrierId) ?? (carrierId ? `#${carrierId}` : '');
    elements.unloadFromDisplay.value = label;
  }
}

function syncUnloadToCompany(idOverride = null, titleOverride = null) {
  if (!elements.unloadToInput) {
    return;
  }

  const companyId = idOverride ?? state.dealMeta?.company?.id ?? null;
  const companyTitle = titleOverride ?? (companyId ? null : state.dealMeta?.company?.title ?? '');

  elements.unloadToInput.value = companyId ? String(companyId) : '';
  if (elements.unloadToDisplay) {
    if (titleOverride !== null) {
      elements.unloadToDisplay.value = titleOverride;
    } else if (companyId === null) {
      elements.unloadToDisplay.value = companyTitle ?? '';
    } else {
      elements.unloadToDisplay.value = companyTitle ?? state.dealMeta?.company?.title ?? `#${companyId}`;
    }
  }
}

function renderSelect(select, items, options) {
  if (!select) return;

  const { placeholder, allowEmpty, getLabel } = options;
  select.innerHTML = '';

  if (!items.length) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'данные недоступны';
    option.disabled = true;
    select.appendChild(option);
    select.disabled = true;
    return;
  }

  select.disabled = false;
  if (allowEmpty) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = placeholder;
    select.appendChild(option);
  } else {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = placeholder;
    option.disabled = true;
    option.selected = true;
    select.appendChild(option);
  }

  items.forEach((item) => {
    const option = document.createElement('option');
    option.value = String(item.id);
    option.textContent = getLabel(item);
    select.appendChild(option);
  });
}

function renderCompanySelect(select, items, placeholder) {
  if (!select) {
    return;
  }

  select.innerHTML = '';

  if (!items.length) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'список пуст';
    option.disabled = true;
    option.selected = true;
    select.appendChild(option);
    select.disabled = true;
    return;
  }

  select.disabled = false;
  const placeholderOption = document.createElement('option');
  placeholderOption.value = '';
  placeholderOption.textContent = placeholder;
  placeholderOption.disabled = true;
  placeholderOption.selected = true;
  select.appendChild(placeholderOption);

  items.forEach((item) => {
    const option = document.createElement('option');
    option.value = String(item.id);
    option.textContent = item.title || `#${item.id}`;
    select.appendChild(option);
  });
}

async function loadHauls() {
  if (!state.dealId) {
    return;
  }

  state.loading = true;
  renderList();
  try {
    const response = await request(`/api/deals/${state.dealId}/hauls`);
    const data = Array.isArray(response?.data) ? response.data : [];
    state.hauls = data.slice().sort(compareHauls);
    clearGlobalError();
  } catch (error) {
    handleApiError(error, { message: 'Не удалось загрузить список рейсов' });
  } finally {
    state.loading = false;
    renderList();
    updateDealMeta();
    scheduleFitWindow();
  }
}

function renderList() {
  if (!elements.haulsList) return;

  const container = elements.haulsList;
  container.classList.remove('empty', 'loading');
  container.innerHTML = '';

  if (state.loading) {
    container.classList.add('loading');
    return;
  }

  if (!state.hauls.length) {
    container.classList.add('empty');
    container.innerHTML = `
      <div class="empty-state">
        <p>Рейсов пока нет.</p>
        <div class="empty-state__actions">
          <button type="button" class="button button--primary" data-action="open-create">Создать рейс</button>
        </div>
      </div>
    `;

    container.querySelector('[data-action="open-create"]')?.addEventListener('click', () => {
      handleCreateRequest();
    });
    return;
  }

  state.hauls.forEach((haul) => {
    container.appendChild(createHaulCard(haul));
  });
}

function createHaulCard(haul) {
  const card = document.createElement('article');
  card.className = 'haul-card';
  card.dataset.haulId = haul.id;

  const header = document.createElement('div');
  header.className = 'haul-card__header';

  const headingRow = document.createElement('div');
  headingRow.className = 'haul-card__heading';

  const mainGroup = document.createElement('div');
  mainGroup.className = 'haul-card__heading-main';

  const title = document.createElement('h3');
  title.className = 'haul-card__title';
  title.textContent = `#${formatSequence(haul)}`;
  mainGroup.appendChild(title);

  const driverName = lookupDriver(haul.responsible_id) || 'Не назначен';
  const truckLabel = lookupLabel(state.trucks, haul.truck_id, 'license_plate');
  const headingDetails = [
    { text: driverName, href: buildDriverProfileUrl(haul.responsible_id) },
    { text: truckLabel },
  ].filter((detail) => detail.text);
  headingDetails.forEach((detail) => {
    mainGroup.appendChild(createHeadingSeparator());
    mainGroup.appendChild(createHeadingDetail(detail.text, { href: detail.href }));
  });

  headingRow.appendChild(mainGroup);

  const statusLabel = getStatusLabel(haul.status);
  if (statusLabel) {
    const aside = document.createElement('div');
    aside.className = 'haul-card__heading-updated';
    const metaInfo = document.createElement('span');
    metaInfo.className = 'tag tag--muted';
    metaInfo.textContent = statusLabel;
    aside.appendChild(metaInfo);
    headingRow.appendChild(aside);
  }
  header.appendChild(headingRow);

  const body = document.createElement('div');
  body.className = 'haul-card__body';
  body.appendChild(createPrimaryInfoRow(haul));
  body.appendChild(createLocationsSection(haul));

  const footer = document.createElement('div');
  footer.className = 'haul-card__actions';

  const editButton = document.createElement('button');
  editButton.type = 'button';
  editButton.className = 'button button--ghost';
  editButton.textContent = 'Открыть';
  editButton.dataset.action = 'edit';
  editButton.dataset.haulId = haul.id;

  const copyButton = document.createElement('button');
  copyButton.type = 'button';
  copyButton.className = 'button button--ghost';
  copyButton.textContent = 'Копировать';
  copyButton.dataset.action = 'copy';
  copyButton.dataset.haulId = haul.id;

  const deleteButton = document.createElement('button');
  deleteButton.type = 'button';
  deleteButton.className = 'button button--ghost';
  deleteButton.textContent = 'Удалить';
  deleteButton.dataset.action = 'delete';
  deleteButton.dataset.haulId = haul.id;

  footer.appendChild(editButton);
  footer.appendChild(copyButton);
  footer.appendChild(deleteButton);

  card.appendChild(header);
  card.appendChild(body);
  card.appendChild(footer);
  return card;
}

function createPrimaryInfoRow(haul) {
  const row = document.createElement('div');
  row.className = 'haul-card__row haul-card__row--primary';

  const materialLabel = lookupLabel(state.materials, haul.material_id, 'name');

  row.appendChild(createInfoItem('Материал', materialLabel));
  const distanceValue = formatDistance(haul.leg_distance_km);
  row.appendChild(createInfoItem('Плечо', distanceValue ? `${distanceValue} км` : '—'));

  return row;
}

function createLocationsSection(haul) {
  const row = document.createElement('div');
  row.className = 'haul-card__row haul-card__row--locations';
  row.appendChild(createAddressInfoItem('Загрузка', haul.load));
  row.appendChild(createAddressInfoItem('Выгрузка', haul.unload));
  return row;
}

function createAddressInfoItem(label, data) {
  if (!data) {
    return createInfoItem(label, '');
  }

  const text = data.address_text || '';
  const url = data.address_url && data.address_text ? data.address_url : null;
  return createInfoItem(label, text, { href: url });
}

function createInfoItem(label, value, options = {}) {
  const { emphasize = false, href = null } = options;
  const wrapper = document.createElement('div');
  wrapper.className = 'haul-card__info-item';
  if (emphasize) {
    wrapper.classList.add('haul-card__info-item--emphasized');
  }

  const labelEl = document.createElement('span');
  labelEl.className = 'haul-card__info-label';
  labelEl.textContent = label;
  wrapper.appendChild(labelEl);

  const displayValue = typeof value === 'string' ? value.trim() : value;
  const text = displayValue ? String(displayValue) : '—';
  const hasLink = Boolean(href && text && text !== '—');

  const valueEl = document.createElement(hasLink ? 'a' : 'span');
  valueEl.className = 'haul-card__info-value';
  if (hasLink) {
    valueEl.href = href;
    valueEl.target = '_blank';
    valueEl.rel = 'noopener noreferrer';
    valueEl.classList.add('haul-card__info-link');
  }
  valueEl.textContent = text;
  wrapper.appendChild(valueEl);

  return wrapper;
}

function createHeadingDetail(text, options = {}) {
  const { href = null } = options;
  const value = typeof text === 'string' ? text.trim() : text;
  const element = document.createElement(href ? 'a' : 'span');
  element.className = 'haul-card__heading-detail';
  if (href) {
    element.href = href;
    element.target = '_blank';
    element.rel = 'noopener noreferrer';
    element.classList.add('haul-card__heading-link');
  }
  element.textContent = value ? String(value) : '—';
  return element;
}

function createHeadingSeparator() {
  const span = document.createElement('span');
  span.className = 'haul-card__heading-separator';
  span.textContent = '—';
  return span;
}

function compareHauls(a, b) {
  const aSeq = typeof a.sequence === 'number' ? a.sequence : Number(a.sequence) || 0;
  const bSeq = typeof b.sequence === 'number' ? b.sequence : Number(b.sequence) || 0;
  return aSeq - bSeq;
}

function formatSequence(haul) {
  const seq = typeof haul.sequence === 'number' ? haul.sequence : Number(haul.sequence);
  if (Number.isFinite(seq) && seq > 0) {
    return seq;
  }
  const allHauls = state.hauls.length ? state.hauls : mobileState.hauls;
  const index = allHauls.findIndex((item) => item.id === haul.id);
  return index >= 0 ? index + 1 : '-';
}

async function resolveHaul(haulId, options = {}) {
  const forceReload = options.forceReload === true;
  if (!forceReload) {
    const existing = state.hauls.find((item) => String(item.id) === String(haulId));
    if (existing) {
      return existing;
    }
  }

  try {
    const response = await request(`/api/hauls/${haulId}`);
    const haul = response?.data;
    if (!haul) {
      return null;
    }
    upsertHaul(haul);
    return haul;
  } catch (error) {
    console.error('Не удалось получить рейс', error);
    return null;
  }
}

function prepareCreateForm() {
  if (!elements.haulForm) return;
  elements.haulForm.reset();
  state.currentHaulSnapshot = null;
  setSelectValue(elements.driverSelect, '');
  setSelectValue(elements.truckSelect, '');
  setSelectValue(elements.materialSelect, '');
   setSelectValue(elements.supplierSelect, '');
   setSelectValue(elements.carrierSelect, '');
  clearFormError();
  elements.submitHaul.textContent = 'Сохранить';
  syncUnloadFromCompany();
  syncUnloadToCompany();
}

function prepareEditForm(haul) {
  if (!elements.haulForm) return;
  elements.haulForm.reset();
  clearFormError();
  applyTemplateToForm(haul, { includeStatus: true });

  elements.submitHaul.textContent = 'Обновить';
}

function applyTemplateToForm(haul, options = {}) {
  if (!elements.haulForm) {
    return;
  }

  const includeStatus = options.includeStatus !== false;

  setSelectValue(elements.driverSelect, haul.responsible_id);
  setSelectValue(elements.truckSelect, haul.truck_id);
  setSelectValue(elements.materialSelect, haul.material_id);
  setSelectValue(elements.supplierSelect, haul.load?.from_company_id);
  setSelectValue(elements.carrierSelect, haul.load?.to_company_id);

  if (includeStatus) {
    setFieldValue('status', haul.status?.value ?? haul.status ?? 0);
  } else {
    setFieldValue('status', 0);
  }

  setFieldValue('general_notes', haul.general_notes);
  setFieldValue('load_volume', haul.load?.volume);
  setFieldValue('leg_distance_km', haul.leg_distance_km);
  setFieldValue('load_address_text', haul.load?.address_text);
  setFieldValue('load_address_url', haul.load?.address_url);
  setFieldValue('unload_address_text', haul.unload?.address_text);
  setFieldValue('unload_address_url', haul.unload?.address_url);
  setFieldValue('unload_contact_name', haul.unload?.contact_name);
  setFieldValue('unload_contact_phone', haul.unload?.contact_phone);
  setFieldValue('unload_acceptance_time', haul.unload?.acceptance_time);

  syncUnloadFromCompany();

  const unloadToId = haul.unload?.to_company_id ?? null;
  if (unloadToId) {
    const sameAsDeal = state.dealMeta?.company?.id === unloadToId;
    const title = sameAsDeal ? state.dealMeta?.company?.title ?? null : `#${unloadToId}`;
    syncUnloadToCompany(unloadToId, title);
  } else {
    syncUnloadToCompany();
  }
}

function setFieldValue(name, value) {
  const field = elements.haulForm?.elements?.namedItem?.(name);
  if (!field) return;
  if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
    if (value === undefined || value === null) {
      field.value = '';
    } else {
      field.value = String(value);
    }
  }
}

function setSelectValue(select, value) {
  if (!select) return;
  const strValue = value === undefined || value === null ? '' : String(value);
  select.value = strValue;
  if (select.value !== strValue) {
    const option = document.createElement('option');
    option.value = strValue;
    option.textContent = strValue;
    select.appendChild(option);
    select.value = strValue;
  }
}

async function onSubmitForm(event) {
  event.preventDefault();
  if (!elements.haulForm || state.saving) return;

  clearFormError();

  const payload = collectFormPayload();
  const errors = validatePayload(payload);
  if (errors.length) {
    showFormError(errors.join(' • '));
    return;
  }

  state.saving = true;
  const originalText = elements.submitHaul?.textContent;
  if (elements.submitHaul) {
    elements.submitHaul.textContent = 'Сохраняем...';
    elements.submitHaul.disabled = true;
  }

  try {
    const isEdit = state.view === views.EDIT && state.currentHaulId;
    const url = isEdit
      ? `/api/hauls/${state.currentHaulId}`
      : `/api/deals/${state.dealId}/hauls`;
    const method = isEdit ? 'PATCH' : 'POST';

    const response = await request(url, { method, body: payload });
    const saved = response?.data;

    if (!saved) {
      throw new Error('Ошибка обработки ответа сервера');
    }

    upsertHaul(saved);
    updateDealMeta();
    navigateTo(views.LIST);
  } catch (error) {
    console.error('Ошибка сохранения рейса', error);
    showFormError(error.message || 'Не удалось сохранить рейс, попробуйте ещё раз');
  } finally {
    state.saving = false;
    if (elements.submitHaul) {
      elements.submitHaul.disabled = false;
      elements.submitHaul.textContent = originalText || 'Сохранить';
    }
  }
}

function collectFormPayload() {
  const formData = new FormData(elements.haulForm);
  const data = Object.fromEntries(formData.entries());

  const payload = {
    responsible_id: toNullableNumber(data.responsible_id),
    truck_id: toNullableString(data.truck_id),
    material_id: toNullableString(data.material_id),
    status: toNullableNumber(data.status) ?? 0,
    general_notes: toNullableString(data.general_notes),
    load_volume: toNullableNumber(data.load_volume),
    load_address_text: toNullableString(data.load_address_text, true),
    load_address_url: toNullableString(data.load_address_url),
    load_from_company_id: toNullableNumber(data.load_from_company_id),
    load_to_company_id: toNullableNumber(data.load_to_company_id),
    leg_distance_km: toNullableNumber(data.leg_distance_km),
    unload_address_text: toNullableString(data.unload_address_text, true),
    unload_address_url: toNullableString(data.unload_address_url),
    unload_from_company_id: toNullableNumber(data.unload_from_company_id),
    unload_to_company_id: toNullableNumber(data.unload_to_company_id),
    unload_contact_name: toNullableString(data.unload_contact_name),
    unload_contact_phone: toNullableString(data.unload_contact_phone),
    unload_acceptance_time: toNullableString(data.unload_acceptance_time),
    load_documents: [],
    unload_documents: [],
  };

  if (state.currentHaulSnapshot) {
    payload.load_documents = Array.isArray(state.currentHaulSnapshot.load?.documents)
      ? [...state.currentHaulSnapshot.load.documents]
      : [];
    payload.unload_documents = Array.isArray(state.currentHaulSnapshot.unload?.documents)
      ? [...state.currentHaulSnapshot.unload.documents]
      : [];
    payload.sequence = state.currentHaulSnapshot.sequence;
  }

  if (payload.responsible_id === null) {
    delete payload.responsible_id;
  }

  return payload;
}

function validatePayload(payload) {
  const errors = [];

  if (!payload.responsible_id) {
    errors.push('Выберите водителя');
  }
  if (!payload.truck_id) {
    errors.push('Выберите самосвал');
  }
  if (!payload.material_id) {
    errors.push('Выберите материал');
  }
  if (!payload.load_address_text) {
    errors.push('Укажите адрес загрузки');
  }
  if (!payload.unload_address_text) {
    errors.push('Укажите адрес выгрузки');
  }

  if (payload.unload_contact_phone) {
    const phonePattern = /^[\d\s()+-]{6,}$/;
    if (!phonePattern.test(payload.unload_contact_phone)) {
      errors.push('Телефон введите в понятном формате (например +7 900 000-00-00)');
    }
  }

  if (payload.leg_distance_km !== null && payload.leg_distance_km < 0) {
    errors.push('Плечо не может быть отрицательным');
  }

  return errors;
}

function showFormError(message) {
  if (elements.formError) {
    elements.formError.textContent = message;
  }
}

function clearFormError() {
  if (elements.formError) {
    elements.formError.textContent = '';
  }
}

function upsertHaul(haul) {
  const index = state.hauls.findIndex((item) => item.id === haul.id);
  if (index >= 0) {
    state.hauls.splice(index, 1, haul);
  } else {
    state.hauls.push(haul);
  }
  state.hauls.sort(compareHauls);
  renderList();
  scheduleFitWindow();
}

async function deleteHaul(haulId) {
  if (!confirm('Удалить рейс?')) {
    return;
  }

  try {
    await request(`/api/hauls/${haulId}`, { method: 'DELETE' });
    state.hauls = state.hauls.filter((haul) => haul.id !== haulId);
    if (state.currentHaulId === haulId) {
      navigateTo(views.LIST);
    } else {
      renderList();
      updateDealMeta();
      scheduleFitWindow();
    }
  } catch (error) {
    handleApiError(error, { message: 'Не удалось удалить рейс, попробуйте ещё раз' });
  }
}

async function handleCopyRequest(haulId) {
  const haul = await resolveHaul(haulId, { forceReload: true });
  if (!haul) {
    handleApiError(null, { message: 'Рейс не найден или был удалён.' });
    return;
  }

  const template = cloneHaulTemplate(haul);
  await navigateTo(views.CREATE, null, { template });
}

async function handleCreateRequest(event) {
  event?.preventDefault?.();

  if (!state.dealId) {
    await detectDealId();
    if (!state.dealId) {
      const extracted = extractDealIdFromReferrer();
      if (extracted) {
        setDealId(extracted);
      }
    }
  } else if (state.hauls.length === 0) {
    await loadHauls();
  }

  if (!state.dealId) {
    showGlobalError('Сначала укажите ID сделки и загрузите список рейсов.');
    return;
  }

  navigateTo(views.CREATE);
}

function openEditor(metaText = '') {
  updateEditorHeader(metaText);
  elements.editorPanel?.setAttribute('aria-hidden', 'false');
  elements.editorPanel?.removeAttribute('hidden');
  elements.mainSection?.setAttribute('hidden', '');

  if (state.embedded && window.BX24) {
    const canResize = typeof window.BX24.resizeWindow === 'function';
    const viewport = typeof window.BX24.getViewportSize === 'function' ? window.BX24.getViewportSize() : null;

    if (canResize && viewport && typeof viewport.height === 'number') {
      const targetWidth = typeof viewport.width === 'number' ? viewport.width : document.documentElement.clientWidth;
      const targetHeight = viewport.height || window.innerHeight;
      window.BX24.resizeWindow(targetWidth, targetHeight);
    } else if (typeof window.BX24.fitWindow === 'function') {
      window.BX24.fitWindow();
    }
  }

  scheduleFitWindow();
}

function closeEditor() {
  elements.editorPanel?.setAttribute('aria-hidden', 'true');
  elements.editorPanel?.setAttribute('hidden', '');
  elements.mainSection?.removeAttribute('hidden');
  state.currentHaulSnapshot = null;
  elements.haulForm?.reset();
  clearFormError();
  scheduleFitWindow();
}

function updatePanelsVisibility(view) {
  if (view === views.LIST) {
    closeEditor();
    return;
  }
  elements.mainSection?.setAttribute('hidden', '');
  elements.editorPanel?.removeAttribute('hidden');
  elements.editorPanel?.setAttribute('aria-hidden', 'false');
}

function lookupLabel(collection, id, field) {
  const item = collection.find((entry) => String(entry.id) === String(id));
  return item ? item[field] || item.id : id ?? '—';
}

function lookupCompanyName(collection, id) {
  if (id === undefined || id === null || id === '') {
    return null;
  }

  const item = collection.find((entry) => String(entry.id) === String(id));
  return item ? item.title || `#${item.id}` : null;
}

function formatPhoneHref(phone) {
  if (!phone) {
    return '';
  }
  const normalized = String(phone).replace(/[^+\d]/g, '');
  return normalized ? `tel:${normalized}` : `tel:${phone}`;
}

function lookupDriver(id) {
  if (!id) return null;
  const driver = state.drivers.find((entry) => String(entry.id) === String(id));
  if (!driver) {
    return id;
  }
  return formatShortName(driver.name) || driver.name || driver.id;
}

function buildDriverProfileUrl(driverId) {
  const base = getPortalBaseUrl();
  if (!base || !driverId) {
    return null;
  }
  const normalizedBase = base.replace(/\/$/, '');
  return `${normalizedBase}/company/personal/user/${driverId}/`;
}

function formatShortName(name) {
  if (typeof name !== 'string') {
    return null;
  }
  const parts = name
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) {
    return null;
  }
  const [lastName, ...rest] = parts;
  if (!rest.length) {
    return lastName;
  }
  const initials = rest
    .map((part) => {
      const [firstChar] = part;
      return firstChar ? `${firstChar.toUpperCase()}.` : '';
    })
    .filter(Boolean)
    .join('');
  return `${lastName} ${initials}`.trim();
}

function toNullableNumber(value) {
  if (value === undefined || value === null || value === '') {
    return null;
  }
  const num = Number(value);
  return Number.isFinite(num) ? num : null;
}

function toNullableString(value, trim = false) {
  if (value === undefined || value === null) {
    return null;
  }
  const str = trim ? String(value).trim() : String(value);
  return str === '' ? null : str;
}

function formatDate(input) {
  const date = new Date(input);
  if (Number.isNaN(date.getTime())) {
    return input;
  }
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function scheduleFitWindow(delay = 120) {
  if (!state.embedded) return;
  if (fitTimer) {
    clearTimeout(fitTimer);
  }
  fitTimer = setTimeout(fitWindow, delay);
}

function fitWindow() {
  if (state.embedded && window.BX24 && typeof window.BX24.fitWindow === 'function') {
    window.BX24.fitWindow();
  }
}

function updateEditorHeader(metaText) {
  if (elements.editorDealTitle) {
    const title = state.dealMeta?.title
      ?? (state.dealId ? `Сделка #${state.dealId}` : 'Сделка');
    elements.editorDealTitle.textContent = title;
  }

  if (elements.editorDealMeta) {
    elements.editorDealMeta.textContent = metaText ?? '';
  }
}

function buildEditorMeta(options) {
  const sequenceLabel = options.sequence && options.sequence !== '-' ? `Рейс №${options.sequence}` : null;

  if (options.mode === 'edit') {
    if (sequenceLabel) {
      return `${sequenceLabel} · редактирование`;
    }
    return 'Редактирование рейса';
  }

  if (sequenceLabel) {
    return `${sequenceLabel} · новый`;
  }
  return 'Новый рейс';
}

function estimateNextSequence() {
  if (state.formTemplate?.sequence) {
    return state.formTemplate.sequence;
  }

  const numericSequences = state.hauls
    .map((haul) => {
      const value = typeof haul.sequence === 'number' ? haul.sequence : Number(haul.sequence);
      return Number.isFinite(value) ? value : null;
    })
    .filter((value) => value !== null);

  if (numericSequences.length) {
    const max = Math.max(...numericSequences);
    return max + 1;
  }

  return state.hauls.length + 1;
}

function extractDealIdFromReferrer() {
  const ref = document.referrer;
  if (!ref) {
    return null;
  }

  try {
    const url = new URL(ref);
    const match = url.pathname.match(/\/crm\/deal\/details\/(\d+)/);
    if (match) {
      const id = Number(match[1]);
      return Number.isFinite(id) ? id : null;
    }
  } catch (error) {
    console.warn('Не удалось разобрать document.referrer', error);
  }

  return null;
}

function extractPlacementOptionsRaw(payload) {
  if (!payload || typeof payload !== 'object') {
    return null;
  }

  return (
    payload.PLACEMENT_OPTIONS ||
    payload.placement_options ||
    payload.PLACEMENT_PARAMS ||
    payload.placement_params ||
    payload.options ||
    payload.OPTIONS ||
    null
  );
}

function parsePlacementOptions(raw) {
  if (!raw) {
    return null;
  }

  if (typeof raw === 'object') {
    return raw;
  }

  if (typeof raw !== 'string') {
    return null;
  }

  let candidate = raw.trim();
  if (!candidate) {
    return null;
  }

  try {
    candidate = decodeURIComponent(candidate);
  } catch (error) {
    // Оставляем исходную строку, если декодирование не требуется
  }

  try {
    return JSON.parse(candidate);
  } catch (error) {
    // Не JSON — пробуем разобрать как query string
  }

  const params = new URLSearchParams(candidate);
  if ([...params.keys()].length === 0) {
    return null;
  }

  const result = {};
  params.forEach((value, key) => {
    result[key] = value;
  });
  return result;
}

function extractDealIdFromObject(subject) {
  if (!subject || typeof subject !== 'object') {
    return null;
  }

  const candidates = [
    subject.entityId,
    subject.entity_id,
    subject.ENTITY_ID,
    subject.dealId,
    subject.deal_id,
    subject.DEAL_ID,
    subject.id,
    subject.ID,
    subject.deal?.id,
    subject.deal?.ID,
    subject.params?.deal_id,
    subject.params?.dealId,
    subject.params?.ID,
    subject.params?.entity_id,
    subject.PARAMS?.deal_id,
    subject.PARAMS?.dealId,
    subject.PARAMS?.ID,
    subject.PARAMS?.entity_id,
  ];

  for (const candidate of candidates) {
    const normalized = normalizeDealId(candidate);
    if (normalized) {
      return normalized;
    }
  }

  return null;
}

function readInitialDealContext() {
  if (typeof window === 'undefined') {
    return { id: null, meta: null };
  }

  try {
    const params = new URLSearchParams(window.location.search || '');
    const idParam = params.get('dealId') || params.get('deal_id');
    const titleParam =
      params.get('dealTitle') ||
      params.get('deal_title') ||
      params.get('DEAL_TITLE');

    let dealId = null;
    if (idParam) {
      const numeric = Number(idParam);
      if (Number.isFinite(numeric)) {
        dealId = numeric;
      } else {
        const digits = idParam.replace(/\D+/g, '');
        const fallback = Number(digits);
        dealId = Number.isFinite(fallback) && fallback > 0 ? fallback : null;
      }
    }

    const title = normalizeDealTitle(titleParam);
    return {
      id: dealId,
      meta: title ? { title } : null,
    };
  } catch (error) {
    return { id: null, meta: null };
  }
}

function applyDealTitle(title, options = {}) {
  const normalized = normalizeDealTitle(title);
  if (!normalized) {
    return;
  }

  const force = Boolean(options.force);

  if (!state.dealMeta) {
    state.dealMeta = { title: normalized };
    updateDealMeta();
    return;
  }

  if (state.dealMeta.title && !force) {
    return;
  }

  state.dealMeta = { ...state.dealMeta, title: normalized };
  updateDealMeta();
}

function normalizeDealTitle(value) {
  if (typeof value !== 'string') {
    return null;
  }
  const replaced = value.replace(/\+/g, ' ');
  const trimmed = replaced.trim();
  return trimmed || null;
}

function extractDealTitleFromObject(subject) {
  if (!subject) {
    return null;
  }

  if (typeof subject === 'string') {
    const trimmed = subject.trim();
    return trimmed || null;
  }

  if (typeof subject.get === 'function') {
    const keys = ['dealTitle', 'deal_title', 'TITLE', 'title', 'name', 'DEAL_NAME', 'dealName'];
    for (const key of keys) {
      const value = subject.get(key);
      if (typeof value === 'string' && value.trim() !== '') {
        return value.trim();
      }
    }
    return null;
  }

  if (typeof subject !== 'object') {
    return null;
  }

  const candidates = [
    subject.dealTitle,
    subject.DEAL_TITLE,
    subject.deal_name,
    subject.dealName,
    subject.TITLE,
    subject.title,
    subject.NAME,
    subject.name,
    subject.deal?.TITLE,
    subject.deal?.title,
    subject.params?.deal_title,
    subject.params?.dealTitle,
    subject.params?.TITLE,
    subject.params?.title,
    subject.PARAMS?.deal_title,
    subject.PARAMS?.dealTitle,
    subject.PARAMS?.TITLE,
    subject.PARAMS?.title,
  ];

  for (const candidate of candidates) {
    if (typeof candidate === 'string' && candidate.trim() !== '') {
      return candidate.trim();
    }
  }

  return null;
}

function normalizeDealId(value) {
  if (value === undefined || value === null) {
    return null;
  }

  if (typeof value === 'number') {
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (!trimmed) {
      return null;
    }

    const numeric = Number(trimmed);
    if (Number.isFinite(numeric) && numeric > 0) {
      return numeric;
    }

    const digits = trimmed.replace(/\D+/g, '');
    const fallback = Number(digits);
    if (Number.isFinite(fallback) && fallback > 0) {
      return fallback;
    }
  }

  return null;
}

function setAppReady(ready) {
  if (!elements.app) {
    return;
  }

  if (ready) {
    elements.app.dataset.initialized = 'true';
    elements.loadingMessage?.classList.add('hidden');
  } else {
    elements.app.dataset.initialized = 'false';
    elements.loadingMessage?.classList.remove('hidden');
  }
}

async function waitForBx24(timeout = 5000) {
  if (!state.embedded) {
    return null;
  }

  if (!window.BX24 && !bx24Ready) {
    bx24Ready = new Promise((resolve) => {
      const started = Date.now();
      const poll = () => {
        if (window.BX24) {
          resolve(window.BX24);
          return;
        }
        if (Date.now() - started >= timeout) {
          resolve(null);
          return;
        }
        setTimeout(poll, 100);
      };
      poll();
    });
  }

  const bx24 = window.BX24 || (await bx24Ready);
  if (!bx24) {
    return null;
  }

  if (!bx24InitPromise) {
    bx24InitPromise = new Promise((resolve) => {
      try {
        bx24.init(() => resolve(bx24));
      } catch (error) {
        console.warn('BX24.init не выполнился, продолжаем без него', error);
        resolve(bx24);
      }
    });
  }

  return bx24InitPromise;
}

async function callBx24Method(method, params = {}) {
  const bx24 = await waitForBx24();
  if (!bx24 || typeof bx24.callMethod !== 'function') {
    throw new Error('BX24 API недоступна');
  }

  return new Promise((resolve, reject) => {
    try {
      bx24.callMethod(method, params, (result) => {
        if (!result) {
          reject(new Error('BX24 вернул пустой ответ'));
          return;
        }

        if (typeof result.error === 'function') {
          const error = result.error();
          if (error) {
            reject(new Error(typeof error === 'string' ? error : 'BX24 error'));
            return;
          }
        } else if (result.error) {
          reject(new Error(String(result.error)));
          return;
        }

        resolve(result.data ?? result.result ?? null);
      });
    } catch (error) {
      reject(error);
    }
  });
}

async function request(path, options = {}) {
  const { method = 'GET', body, headers = {} } = options;

  const init = {
    method,
    headers: {
      Accept: 'application/json',
      ...headers,
    },
    credentials: 'include',
  };

  if (state.actor) {
    if (state.actor.id) {
      init.headers['X-Actor-Id'] = String(state.actor.id);
    }
    if (state.actor.name) {
      init.headers['X-Actor-Name'] = String(state.actor.name);
    }
    if (state.actor.role) {
      init.headers['X-Actor-Role'] = state.actor.role;
    }
  }

  if (body !== undefined) {
    init.body = typeof body === 'string' ? body : JSON.stringify(body);
    init.headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(apiBase + path, init);
  const contentType = response.headers.get('content-type') || '';
  const isJson = contentType.includes('application/json');
  const data = isJson ? await response.json() : null;

  if (!response.ok) {
    const message = data?.error || response.statusText || 'Request failed';
    throw new Error(message);
  }

  return data ?? {};
}

function handleApiError(error, options = {}) {
  const config = typeof options === 'string' ? { message: options } : options ?? {};
  const fallback = config.message || 'Произошла ошибка. Попробуйте позже.';
  const message = error instanceof Error && error.message ? error.message : fallback;

  console.error('API error', error);

  switch (config.target) {
    case 'mobile':
      setMobileError(message);
      break;
    case 'mobile-login':
      setMobileLoginError(message);
      break;
    case 'mobile-dialog':
      setMobileDialogError(message);
      break;
    case 'form':
      setFormError(message);
      break;
    default:
      showGlobalError(message);
  }

  return message;
}
function cloneHaulTemplate(haul) {
  if (!haul || typeof haul !== 'object') {
    return null;
  }
  if (typeof structuredClone === 'function') {
    try {
      return structuredClone(haul);
    } catch (error) {
      console.warn('structuredClone failed, fallback to JSON clone', error);
    }
  }
  try {
    return JSON.parse(JSON.stringify(haul));
  } catch (error) {
    console.warn('JSON clone failed', error);
    return { ...haul };
  }
}
