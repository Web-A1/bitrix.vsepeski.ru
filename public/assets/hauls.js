const apiBase = '';

const views = {
  LIST: 'list',
  CREATE: 'create',
  EDIT: 'edit',
};

const state = {
  dealId: null,
  view: views.LIST,
  currentHaulId: null,
  currentHaulSnapshot: null,
  trucks: [],
  materials: [],
  drivers: [],
  hauls: [],
  embedded: window !== window.top,
  loading: false,
  saving: false,
};

const elements = {
  app: document.getElementById('app'),
  dealInput: document.getElementById('deal-id-input'),
  dealLabel: document.getElementById('deal-label'),
  dealSubtitle: document.getElementById('deal-subtitle'),
  loadButton: document.getElementById('load-hauls'),
  openCreate: document.getElementById('open-create'),
  floatingCreate: document.getElementById('floating-create'),
  haulsList: document.getElementById('hauls-list'),
  editorOverlay: document.getElementById('editor-overlay'),
  editorMode: document.getElementById('editor-mode'),
  haulForm: document.getElementById('haul-form'),
  driverSelect: document.getElementById('driver-select'),
  truckSelect: document.getElementById('truck-select'),
  materialSelect: document.getElementById('material-select'),
  formError: document.getElementById('form-error'),
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
  0: 'Подготовка рейса',
  1: 'Рейс в работе',
  2: 'Загрузился',
  3: 'Выгрузился',
  4: 'Проверено',
};

const driverVisibleStatuses = new Set([1, 2, 3]);

let fitTimer = null;
let bx24Ready = null;

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
  await loadReferenceData();
  await detectDealId();
  updateDealMeta();
  initRouter();
  if (state.dealId) {
    await loadHauls();
  } else {
    renderList();
  }
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
    const response = await fetch(apiBase + '/api/auth/me', {
      headers: { Accept: 'application/json' },
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error('Unauthorized');
    }

    const data = await readJsonResponse(response);
    if (!data?.data) {
      throw new Error('Unauthorized');
    }

    mobileState.user = data.data;
    showMobileHauls();
    await loadMobileHauls();
  } catch (error) {
    mobileState.user = null;
    showMobileLogin();
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
    const response = await fetch(apiBase + '/api/auth/login', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify({ login, password }),
    });

    const data = await readJsonResponse(response);
    if (!response.ok) {
      const message = data?.error || 'Не удалось авторизоваться.';
      throw new Error(message);
    }

    if (!data?.data) {
      throw new Error('Не удалось получить данные пользователя.');
    }

    mobileState.user = data.data;
    mobileStorage.saveLogin(login);
    showMobileHauls();
    await loadMobileHauls();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Не удалось авторизоваться.';
    setMobileLoginError(message);
  } finally {
    setMobileLoading(false);
  }
}

async function mobileLogout() {
  try {
    await fetch(apiBase + '/api/auth/logout', {
      method: 'POST',
      credentials: 'include',
    });
  } catch (error) {
    console.warn('Ошибка при выходе', error);
  } finally {
    mobileState.user = null;
    mobileState.selectedHaulId = null;
    closeMobileHaulDetails();
    showMobileLogin();
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
    const response = await fetch(apiBase + '/api/mobile/hauls', {
      headers: { Accept: 'application/json' },
      credentials: 'include',
    });

    if (response.status === 401) {
      mobileState.user = null;
      showMobileLogin();
      return;
    }

    const data = await readJsonResponse(response);
    if (!response.ok) {
      const message = data?.error || 'Не удалось загрузить рейсы.';
      throw new Error(message);
    }

    mobileState.hauls = Array.isArray(data?.data) ? data.data : [];
    renderMobileHauls();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Не удалось загрузить рейсы.';
    setMobileError(message);
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
    const response = await fetch(apiBase + `/api/mobile/hauls/${haulId}/status`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify({ status, ...extra }),
    });

    const data = await readJsonResponse(response);
    if (!response.ok) {
      const message = data?.error || 'Не удалось обновить статус.';
      throw new Error(message);
    }

    if (data?.data) {
      applyMobileHaulUpdate(data.data);
      renderMobileHauls();
      if (mobileState.selectedHaulId === data.data.id) {
        renderMobileHaulDetails(data.data);
      }
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Не удалось обновить статус.';
    setMobileDialogError(message);
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
      alert('Введите корректный ID сделки');
      return;
    }
    setDealId(dealId);
    navigateTo(views.LIST);
    loadHauls();
  });

  elements.openCreate?.addEventListener('click', handleCreateRequest);
  elements.floatingCreate?.addEventListener('click', handleCreateRequest);

  elements.closeEditor?.addEventListener('click', () => navigateTo(views.LIST));
  elements.cancelEditor?.addEventListener('click', () => navigateTo(views.LIST));

  elements.editorOverlay?.addEventListener('click', (event) => {
    if (event.target === elements.editorOverlay) {
      navigateTo(views.LIST);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.view !== views.LIST) {
      navigateTo(views.LIST);
    }
  });

  elements.haulForm?.addEventListener('submit', onSubmitForm);

  elements.haulsList?.addEventListener('click', (event) => {
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
}

function initRouter() {
  window.addEventListener('hashchange', () => {
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

function navigateTo(view, haulId = null) {
  void applyView(view, haulId, { updateHash: true });
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
      window.location.hash = targetHash;
    }
  }

  state.view = view;
  state.currentHaulId = view === views.EDIT ? haulId : null;
  state.currentHaulSnapshot = null;
  elements.app?.setAttribute('data-view', view);

  if (view === views.LIST) {
    closeEditor();
    renderList();
    scheduleFitWindow();
    return;
  }

  if (!state.dealId) {
    alert('Сначала выберите сделку.');
    navigateTo(views.LIST);
    return;
  }

  if (view === views.CREATE) {
    prepareCreateForm();
    openEditor('Создание рейса');
    return;
  }

  if (view === views.EDIT && haulId) {
    const haul = await resolveHaul(haulId);
    if (!haul) {
      alert('Рейс не найден или был удалён.');
      navigateTo(views.LIST);
      return;
    }

    state.currentHaulSnapshot = haul;
    prepareEditForm(haul);
    openEditor(`Редактирование рейса №${formatSequence(haul)}`);
  }
}

function setDealId(id) {
  state.dealId = id;
  if (elements.dealInput) {
    elements.dealInput.value = id;
  }
  updateDealMeta();
}

function updateDealMeta() {
  const label = state.dealId ? `#${state.dealId}` : '—';
  if (elements.dealLabel) {
    elements.dealLabel.textContent = label;
  }

  if (!elements.dealSubtitle) {
    return;
  }

  if (!state.dealId) {
    elements.dealSubtitle.textContent = 'Загрузите существующие рейсы или создайте новый.';
    return;
  }

  const count = state.hauls.length;
  if (count === 0) {
    elements.dealSubtitle.textContent = 'Рейсов ещё нет — создайте первый рейс.';
  } else {
    elements.dealSubtitle.textContent = `Всего рейсов: ${count}`;
  }
}

async function detectDealId() {
  const bootstrap = window.B24_INSTALL_PAYLOAD || null;
  const bootstrapPayload = bootstrap?.payload || null;
  const bootstrapQuery = bootstrap?.get || bootstrap?.query || null;
  const bootstrapRequest = bootstrap?.request || null;

  const bootstrapOptionsRaw = extractPlacementOptionsRaw(bootstrapPayload);
  if (bootstrapOptionsRaw) {
    const options = parsePlacementOptions(bootstrapOptionsRaw);
    const idFromOptions = extractDealIdFromObject(options);
    if (idFromOptions) {
      setDealId(idFromOptions);
      return;
    }
  }

  const idFromPayload = extractDealIdFromObject(bootstrapPayload);
  if (idFromPayload) {
    setDealId(idFromPayload);
    return;
  }

  const idFromQuery = extractDealIdFromObject(bootstrapQuery);
  if (idFromQuery) {
    setDealId(idFromQuery);
    return;
  }

  const idFromRequest = extractDealIdFromObject(bootstrapRequest);
  if (idFromRequest) {
    setDealId(idFromRequest);
    return;
  }

  const requestOptionsRaw = extractPlacementOptionsRaw(bootstrapRequest);
  if (requestOptionsRaw) {
    const requestOptions = parsePlacementOptions(requestOptionsRaw);
    const idFromRequestOptions = extractDealIdFromObject(requestOptions);
    if (idFromRequestOptions) {
      setDealId(idFromRequestOptions);
      return;
    }
  }

  const searchParams = new URLSearchParams(window.location.search);
  const placementOptionsRaw =
    searchParams.get('PLACEMENT_OPTIONS') ||
    searchParams.get('placement_options');

  if (placementOptionsRaw) {
    const parsedOptions = parsePlacementOptions(placementOptionsRaw);
    const optionDealId = extractDealIdFromObject(parsedOptions);
    if (optionDealId) {
      setDealId(optionDealId);
      return;
    }
  }

  const candidate = searchParams.get('dealId') || searchParams.get('deal_id');

  if (candidate) {
    const numericId = Number(candidate);
    if (Number.isFinite(numericId)) {
      setDealId(numericId);
      return;
    }

    const digits = candidate.replace(/\D+/g, '');
    const fallbackId = Number(digits);
    if (Number.isFinite(fallbackId) && digits.length > 0) {
      setDealId(fallbackId);
      return;
    }
  }

  const referrerId = extractDealIdFromReferrer();
  if (referrerId) {
    setDealId(referrerId);
    return;
  }

  if (!state.embedded) {
    return;
  }

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

        const applyDealId = (possible) => {
          const numericId = Number(possible);
          if (Number.isFinite(numericId)) {
            setDealId(numericId);
            if (state.hauls.length === 0) {
              loadHauls();
            }
            finish();
          }
        };

        let placementInfo = null;
        let placementParams = null;
        try {
          placementInfo = typeof bx24.placement?.info === 'function'
            ? bx24.placement.info()
            : null;
          const placementId = placementInfo?.entity_id
            ?? placementInfo?.deal_id
            ?? placementInfo?.ID
            ?? placementInfo?.ENTITY_ID;
          if (placementId) {
            applyDealId(placementId);
          }
        } catch (infoError) {
          console.warn('BX24 placement info недоступна', infoError);
        }

        try {
          placementParams = typeof bx24.placement?.getParams === 'function'
            ? bx24.placement.getParams()
            : null;
          const paramsId = placementParams?.deal_id
            ?? placementParams?.dealId
            ?? placementParams?.ID
            ?? placementParams?.entity_id
            ?? placementParams?.ENTITY_ID;
          if (paramsId) {
            applyDealId(paramsId);
          }
        } catch (paramsError) {
          console.warn('BX24 placement params недоступны', paramsError);
        }

        if (typeof bx24.getPageParams === 'function') {
          bx24.getPageParams((params) => {
            const possible = params?.deal_id || params?.ID || params?.entity_id || params?.ENTITY_ID;
            applyDealId(possible);
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
                applyDealId(result.data.ID);
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

async function loadReferenceData() {
  try {
    const [trucks, materials] = await Promise.all([
      request('/api/trucks'),
      request('/api/materials'),
    ]);

    let driversResponse = null;
    try {
      driversResponse = await request('/api/drivers');
    } catch (error) {
      console.warn('Не удалось загрузить список водителей', error);
    }

    state.trucks = Array.isArray(trucks?.data) ? trucks.data : [];
    state.materials = Array.isArray(materials?.data) ? materials.data : [];

    const driverData = driversResponse?.data ?? driversResponse ?? [];
    state.drivers = Array.isArray(driverData) ? driverData : Object.values(driverData);
    renderReferenceSelects();
  } catch (error) {
    console.error('Не удалось загрузить справочники', error);
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
  } catch (error) {
    console.error('Ошибка загрузки рейсов', error);
    alert('Не удалось загрузить список рейсов');
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

  const headingWrapper = document.createElement('div');
  const title = document.createElement('h3');
  title.className = 'haul-card__title';
  title.textContent = `Рейс №${formatSequence(haul)}`;

  const meta = document.createElement('div');
  meta.className = 'haul-card__meta';
  const driverName = lookupDriver(haul.responsible_id) || 'Не назначен';
  const truckLabel = lookupLabel(state.trucks, haul.truck_id, 'license_plate');
  const materialLabel = lookupLabel(state.materials, haul.material_id, 'name');

  meta.appendChild(createTag(driverName, false));
  meta.appendChild(createTag(truckLabel, false));
  meta.appendChild(createTag(materialLabel, true));

  headingWrapper.appendChild(title);
  headingWrapper.appendChild(meta);
  header.appendChild(headingWrapper);

  const body = document.createElement('div');
  body.className = 'haul-card__body';
  body.appendChild(createAddressSection('Загрузка', haul.load));
  body.appendChild(createAddressSection('Выгрузка', haul.unload, true));

  const footer = document.createElement('div');
  footer.className = 'haul-card__actions';

  const editButton = document.createElement('button');
  editButton.type = 'button';
  editButton.className = 'button button--ghost';
  editButton.textContent = 'Открыть';
  editButton.dataset.action = 'edit';
  editButton.dataset.haulId = haul.id;

  const deleteButton = document.createElement('button');
  deleteButton.type = 'button';
  deleteButton.className = 'button button--ghost';
  deleteButton.textContent = 'Удалить';
  deleteButton.dataset.action = 'delete';
  deleteButton.dataset.haulId = haul.id;

  footer.appendChild(editButton);
  footer.appendChild(deleteButton);

  if (haul.updated_at) {
    const metaInfo = document.createElement('span');
    metaInfo.className = 'tag tag--muted';
    metaInfo.textContent = `Обновлено ${formatDate(haul.updated_at)}`;
    footer.appendChild(metaInfo);
  }

  card.appendChild(header);
  card.appendChild(body);
  card.appendChild(footer);
  return card;
}

function createAddressSection(title, data, isUnload = false) {
  const section = document.createElement('div');
  section.className = 'haul-card__section';

  const heading = document.createElement('strong');
  heading.textContent = title;
  section.appendChild(heading);

  const address = document.createElement('div');
  address.textContent = data?.address_text || '—';
  section.appendChild(address);

  if (data?.address_url) {
    const link = document.createElement('a');
    link.href = data.address_url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Открыть на карте';
    section.appendChild(link);
  }

  const details = [];
  if (data?.from_company_id) {
    details.push(`От кого: ${data.from_company_id}`);
  }
  if (data?.to_company_id) {
    details.push(`Кому: ${data.to_company_id}`);
  }
  if (!isUnload && Number.isFinite(Number(data?.volume))) {
    details.push(`Объём: ${Number(data.volume).toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} м³`);
  }
  if (isUnload && (data?.contact_name || data?.contact_phone)) {
    details.push(`Контакт: ${[data.contact_name, data.contact_phone].filter(Boolean).join(', ')}`);
  }

  if (details.length) {
    const detailsEl = document.createElement('div');
    detailsEl.className = 'haul-card__meta';
    detailsEl.textContent = details.join(' · ');
    section.appendChild(detailsEl);
  }

  return section;
}

function createTag(text, muted) {
  const span = document.createElement('span');
  span.className = muted ? 'tag tag--muted' : 'tag';
  span.textContent = text || '—';
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

async function resolveHaul(haulId) {
  const existing = state.hauls.find((item) => item.id === haulId);
  if (existing) {
    return existing;
  }

  try {
    const response = await request(`/api/hauls/${haulId}`);
    const haul = response?.data;
    if (!haul) {
      return null;
    }
    state.hauls.push(haul);
    state.hauls.sort(compareHauls);
    renderList();
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
  clearFormError();
  elements.submitHaul.textContent = 'Сохранить';
}

function prepareEditForm(haul) {
  if (!elements.haulForm) return;
  elements.haulForm.reset();
  clearFormError();

  setSelectValue(elements.driverSelect, haul.responsible_id);
  setSelectValue(elements.truckSelect, haul.truck_id);
  setSelectValue(elements.materialSelect, haul.material_id);
  setFieldValue('status', haul.status?.value ?? haul.status);
  setFieldValue('general_notes', haul.general_notes);

  setFieldValue('load_volume', haul.load?.volume);
  setFieldValue('load_address_text', haul.load?.address_text);
  setFieldValue('load_address_url', haul.load?.address_url);
  setFieldValue('load_from_company_id', haul.load?.from_company_id);
  setFieldValue('load_to_company_id', haul.load?.to_company_id);
  setFieldValue('unload_address_text', haul.unload?.address_text);
  setFieldValue('unload_address_url', haul.unload?.address_url);
  setFieldValue('unload_from_company_id', haul.unload?.from_company_id);
  setFieldValue('unload_to_company_id', haul.unload?.to_company_id);
  setFieldValue('unload_contact_name', haul.unload?.contact_name);
  setFieldValue('unload_contact_phone', haul.unload?.contact_phone);
  setFieldValue('unload_acceptance_time', haul.unload?.acceptance_time);

  elements.submitHaul.textContent = 'Обновить';
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
    console.error('Не удалось удалить рейс', error);
    alert('Не удалось удалить рейс, попробуйте ещё раз');
  }
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
    alert('Сначала укажите ID сделки и загрузите список рейсов.');
    return;
  }

  navigateTo(views.CREATE);
}

function openEditor(modeText) {
  elements.editorMode.textContent = modeText;
  elements.editorOverlay.classList.add('is-open');
  elements.editorOverlay.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  document.body.classList.add('editor-open');

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
  elements.editorOverlay.classList.remove('is-open');
  elements.editorOverlay.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  document.body.classList.remove('editor-open');
  state.currentHaulSnapshot = null;
  elements.haulForm?.reset();
  clearFormError();
  scheduleFitWindow();
}

function lookupLabel(collection, id, field) {
  const item = collection.find((entry) => String(entry.id) === String(id));
  return item ? item[field] || item.id : id ?? '—';
}

function lookupDriver(id) {
  if (!id) return null;
  const driver = state.drivers.find((entry) => String(entry.id) === String(id));
  return driver ? driver.name : id;
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

async function waitForBx24(timeout = 5000) {
  if (!state.embedded) {
    return null;
  }

  if (window.BX24) {
    return window.BX24;
  }

  if (!bx24Ready) {
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

  return bx24Ready;
}

async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch (error) {
    return null;
  }
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
