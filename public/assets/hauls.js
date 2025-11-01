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

let fitTimer = null;
let bx24Ready = null;

init().catch((error) => {
  console.error('Ошибка инициализации', error);
});

async function init() {
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
  const searchParams = new URLSearchParams(window.location.search);
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
  const index = state.hauls.findIndex((item) => item.id === haul.id);
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
  scheduleFitWindow();
}

function closeEditor() {
  elements.editorOverlay.classList.remove('is-open');
  elements.editorOverlay.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  state.currentHaulSnapshot = null;
  elements.haulForm?.reset();
  clearFormError();
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

async function request(path, options = {}) {
  const { method = 'GET', body, headers = {} } = options;

  const init = {
    method,
    headers: {
      Accept: 'application/json',
      ...headers,
    },
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
