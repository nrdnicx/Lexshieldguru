(() => {
  const root = document.documentElement;
  const themeStorageKey = 'lex-theme';
  const themeToggle = document.getElementById('themeToggle');
  const moonIcon = '&#9681;';
  const sunIcon = '&#9728;';

  const getPreferredTheme = () => {
    const storedTheme = localStorage.getItem(themeStorageKey);
    if (storedTheme === 'dark' || storedTheme === 'light') {
      return storedTheme;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  };

  const applyTheme = (theme) => {
    const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
    root.dataset.theme = normalizedTheme;
    localStorage.setItem(themeStorageKey, normalizedTheme);

    if (themeToggle) {
      const isDark = normalizedTheme === 'dark';
      themeToggle.innerHTML = isDark ? sunIcon : moonIcon;
      themeToggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      themeToggle.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      themeToggle.setAttribute('aria-pressed', String(isDark));
    }
  };

  applyTheme(getPreferredTheme());

  const disableSavedInputSuggestions = (scope = document) => {
    const forms = scope.querySelectorAll ? scope.querySelectorAll('form') : [];
    forms.forEach((form) => {
      if (form.dataset.allowAutocomplete === 'true') return;
      form.setAttribute('autocomplete', 'off');
    });

    const fields = scope.querySelectorAll
      ? scope.querySelectorAll('input:not([type="password"]):not([type="hidden"]):not([type="file"]), textarea')
      : [];
    fields.forEach((field) => {
      if (field.dataset.allowAutocomplete === 'true') return;
      field.setAttribute('autocomplete', 'off');
      field.setAttribute('autocorrect', 'off');
      field.setAttribute('spellcheck', 'false');
    });
  };

  disableSavedInputSuggestions();

  const suggestionObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        disableSavedInputSuggestions(node);
      });
    });
  });
  suggestionObserver.observe(document.documentElement, { childList: true, subtree: true });

  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  if (toggle && sidebar) {
    const closeSidebar = () => {
      sidebar.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('sidebar-is-open');
    };

    const openSidebar = () => {
      sidebar.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('sidebar-is-open');
    };

    toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
    toggle.setAttribute('aria-controls', sidebar.id);

    toggle.addEventListener('click', () => {
      if (sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    document.addEventListener('click', (event) => {
      if (!sidebar.classList.contains('open')) return;
      if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
      closeSidebar();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebar.classList.contains('open')) {
        closeSidebar();
        toggle.focus();
      }
    });

    sidebar.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeSidebar);
    });
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }


  // Shared notification dropdown behavior for admin, lawyer, and client.
  // The bell opens the panel; clicking the bell again, outside the panel,
  // or pressing Escape closes it. Native <details> behavior remains supported.
  const notificationMenus = document.querySelectorAll('.notification-menu');
  notificationMenus.forEach((menu) => {
    const summary = menu.querySelector(':scope > summary');
    const panel = menu.querySelector(':scope > .notification-panel');
    if (!(summary instanceof HTMLElement) || !(panel instanceof HTMLElement)) return;

    summary.addEventListener('click', (event) => {
      event.preventDefault();
      menu.open = !menu.open;
      summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
    });

    menu.addEventListener('toggle', () => {
      summary.setAttribute('aria-expanded', menu.open ? 'true' : 'false');
    });

    panel.addEventListener('click', (event) => {
      event.stopPropagation();
    });
  });

  document.addEventListener('click', (event) => {
    notificationMenus.forEach((menu) => {
      if (!menu.open) return;
      if (menu.contains(event.target)) return;
      menu.open = false;
      const summary = menu.querySelector(':scope > summary');
      if (summary instanceof HTMLElement) {
        summary.setAttribute('aria-expanded', 'false');
      }
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    notificationMenus.forEach((menu) => {
      if (!menu.open) return;
      menu.open = false;
      const summary = menu.querySelector(':scope > summary');
      if (summary instanceof HTMLElement) {
        summary.setAttribute('aria-expanded', 'false');
        summary.focus();
      }
    });
  });

  if (document.querySelector('[data-appointment-board]') || document.querySelector('[data-system-settings-page]')) {
    document.body.classList.add('toast-upper');
  }

  const eyeSvg = `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M12 5c5.5 0 9.9 3.4 11.5 7-1.6 3.6-6 7-11.5 7S2.1 15.6.5 12C2.1 8.4 6.5 5 12 5Zm0 2.2A4.8 4.8 0 1 0 12 19a4.8 4.8 0 0 0 0-9.6Z"/>
    </svg>`;
  const eyeOffSvg = `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M3 3 21 21"/>
      <path d="M2.5 12s3.6-7 9.5-7c2.1 0 3.9.6 5.3 1.5L18.9 8A18.7 18.7 0 0 1 22 12c-1.6 3.6-6 7-10 7-1.7 0-3.3-.4-4.8-1.1l1.9-1.9A4.8 4.8 0 0 0 12 19a4.8 4.8 0 1 0-4.8-4.8c0 .7.1 1.3.4 1.9L5.8 17C3.4 15.2 2.5 12 2.5 12Zm9.5-4.8a4.8 4.8 0 0 1 4.8 4.8c0 .4 0 .7-.1 1.1l-2-2A2.8 2.8 0 0 0 12 9.2c-.4 0-.8.1-1.2.2L9 9a4.7 4.7 0 0 1 3-1.8Z"/>
    </svg>`;

  const syncPasswordToggleGroup = (group) => {
    const input = group.querySelector('input[type="password"], input[type="text"]');
    const button = group.querySelector('[data-password-toggle-button]');
    if (!input || !button) return;

    const isHidden = input.type === 'password';
    button.type = 'button';
    button.innerHTML = isHidden ? eyeSvg : eyeOffSvg;
    button.setAttribute('aria-pressed', String(!isHidden));
    button.setAttribute('aria-label', isHidden ? 'Show password' : 'Hide password');
    button.title = isHidden ? 'Show password' : 'Hide password';
  };

  const initPasswordToggleGroup = (group) => {
    if (!(group instanceof HTMLElement)) return;
    if (group.dataset.passwordToggleReady === 'true') {
      syncPasswordToggleGroup(group);
      return;
    }

    const input = group.querySelector('input[type="password"], input[type="text"]');
    const button = group.querySelector('[data-password-toggle-button]');
    if (!input || !button) return;

    group.dataset.passwordToggleReady = 'true';
    syncPasswordToggleGroup(group);
  };

  document.querySelectorAll('[data-password-toggle]').forEach(initPasswordToggleGroup);

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle-button]');
    if (!(button instanceof HTMLElement)) return;

    const group = button.closest('[data-password-toggle]');
    if (!(group instanceof HTMLElement)) return;

    const input = group.querySelector('input[type="password"], input[type="text"]');
    if (!(input instanceof HTMLInputElement)) return;

    event.preventDefault();
    input.type = input.type === 'password' ? 'text' : 'password';
    syncPasswordToggleGroup(group);
    input.focus();
  });

  const passwordToggleObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        if (node.matches('[data-password-toggle]')) {
          initPasswordToggleGroup(node);
        }
        node.querySelectorAll?.('[data-password-toggle]').forEach(initPasswordToggleGroup);
      });
    });
  });

  passwordToggleObserver.observe(document.body, {
    childList: true,
    subtree: true,
  });

  const toastTypes = new Set(['success', 'error', 'danger', 'warning', 'info', 'neutral', 'default']);
  const toastTitles = {
    success: 'Success',
    error: 'Error',
    danger: 'Error',
    warning: 'Warning',
    info: 'Information',
    neutral: 'Notice',
    default: 'Notice',
  };
  const toastDurations = {
    success: 5600,
    error: 8200,
    danger: 8200,
    warning: 7200,
    info: 6200,
    neutral: 6200,
    default: 6200,
  };
  const recentToasts = new Map();

  const normalizeToastType = (type) => {
    const normalized = String(type || 'default').trim().toLowerCase();
    return toastTypes.has(normalized) ? (normalized === 'danger' ? 'error' : normalized) : 'default';
  };

  const getToastStack = () => {
    let stack = document.querySelector('[data-toast-stack], .toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      stack.dataset.toastStack = 'true';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('aria-atomic', 'true');
      document.body.appendChild(stack);
    }
    return stack;
  };

  const readToastMessage = (toast) => {
    const message = toast.querySelector('.toast-message');
    if (message) return message.textContent.trim();
    return toast.textContent.trim();
  };

  const dismissToast = (toast) => {
    if (!(toast instanceof HTMLElement) || toast.dataset.toastRemoving === 'true') return;
    toast.dataset.toastRemoving = 'true';
    window.clearTimeout(Number(toast.dataset.toastTimer || 0));
    toast.classList.remove('is-paused');
    toast.classList.add('is-dismissing');
    window.setTimeout(() => toast.remove(), 280);
  };

  const scheduleToastDismiss = (toast, duration) => {
    if (toast.dataset.toastPersistent === 'true' || duration <= 0) {
      return;
    }

    const startedAt = Date.now();
    let remaining = duration;
    let timer = 0;

    const startTimer = () => {
      window.clearTimeout(timer);
      toast.dataset.toastStartedAt = String(Date.now());
      timer = window.setTimeout(() => dismissToast(toast), remaining);
      toast.dataset.toastTimer = String(timer);
    };

    const pauseTimer = () => {
      window.clearTimeout(timer);
      const activeFor = Date.now() - Number(toast.dataset.toastStartedAt || startedAt);
      remaining = Math.max(900, remaining - activeFor);
      toast.classList.add('is-paused');
    };

    const resumeTimer = () => {
      if (toast.dataset.toastRemoving === 'true') return;
      toast.classList.remove('is-paused');
      startTimer();
    };

    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('mouseleave', resumeTimer);
    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('focusout', resumeTimer);
    toast.addEventListener('pointerdown', pauseTimer);
    toast.addEventListener('pointerup', resumeTimer);
    toast.addEventListener('pointercancel', resumeTimer);
    startTimer();
  };

  const enhanceToast = (toast) => {
    if (!(toast instanceof HTMLElement) || toast.dataset.toastReady === 'true') {
      return toast;
    }

    const typeClass = Array.from(toast.classList).find((name) => name.startsWith('toast-'));
    const type = normalizeToastType(toast.dataset.toastType || (typeClass ? typeClass.replace('toast-', '') : 'default'));
    const existingMessage = readToastMessage(toast);
    const title = toast.dataset.toastTitle || toastTitles[type] || toastTitles.default;
    const duration = Number(toast.dataset.dismissAfter || toastDurations[type] || toastDurations.default);

    toast.dataset.toastReady = 'true';
    toast.dataset.toastType = type;
    toast.dataset.dismissAfter = String(duration);
    toast.classList.add('toast', `toast-${type}`);
    toast.classList.add(type);
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-atomic', 'true');
    toast.style.setProperty('--toast-duration', `${duration}ms`);

    if (!toast.querySelector('.toast-content')) {
      toast.textContent = '';
      const icon = document.createElement('span');
      icon.className = 'toast-icon';
      icon.setAttribute('aria-hidden', 'true');

      const content = document.createElement('span');
      content.className = 'toast-content';

      const titleNode = document.createElement('strong');
      titleNode.className = 'toast-title';
      titleNode.textContent = title;

      const messageNode = document.createElement('span');
      messageNode.className = 'toast-message';
      messageNode.textContent = existingMessage;

      content.append(titleNode, messageNode);
      toast.append(icon, content);
    }

    let closeButton = toast.querySelector('.toast-close');
    if (!closeButton) {
      closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'toast-close';
      closeButton.setAttribute('aria-label', 'Dismiss notification');
      closeButton.textContent = '×';
      toast.appendChild(closeButton);
    }
    closeButton.addEventListener('click', () => dismissToast(toast));

    if (!toast.querySelector('.toast-progress') && toast.dataset.toastPersistent !== 'true' && duration > 0) {
      const progress = document.createElement('span');
      progress.className = 'toast-progress';
      progress.setAttribute('aria-hidden', 'true');
      toast.appendChild(progress);
    }

    requestAnimationFrame(() => toast.classList.add('is-visible'));
    scheduleToastDismiss(toast, duration);
    return toast;
  };

  const showNotification = (type, message, options = {}) => {
    let normalizedType = normalizeToastType(type);
    let normalizedMessage = message;
    const firstLooksLikeMessage = !toastTypes.has(String(type || '').trim().toLowerCase());
    const secondLooksLikeType = toastTypes.has(String(message || '').trim().toLowerCase());
    if (firstLooksLikeMessage && secondLooksLikeType) {
      normalizedType = normalizeToastType(message);
      normalizedMessage = type;
    }
    if (normalizedMessage === undefined || typeof normalizedMessage === 'object') {
      normalizedMessage = type;
      normalizedType = normalizeToastType(options.type || 'default');
    }

    const text = String(normalizedMessage || '').trim();
    if (!text) return null;

    const signature = `${normalizedType}:${text}`;
    const now = Date.now();
    if (recentToasts.has(signature) && now - recentToasts.get(signature) < 1500) {
      return null;
    }
    recentToasts.set(signature, now);
    window.setTimeout(() => recentToasts.delete(signature), 1600);

    const toast = document.createElement('div');
    toast.className = `toast toast-${normalizedType}`;
    toast.dataset.toastType = normalizedType;
    if (options.title) toast.dataset.toastTitle = String(options.title);
    if (options.persistent) toast.dataset.toastPersistent = 'true';
    if (options.duration !== undefined) toast.dataset.dismissAfter = String(Number(options.duration) || 0);
    toast.textContent = text;
    getToastStack().appendChild(toast);
    return enhanceToast(toast);
  };

  const showToast = (type = 'success', title = '', message = '', duration = 5000) => {
    const normalizedType = normalizeToastType(type);
    const text = String(message || '').trim();
    const heading = String(title || toastTitles[normalizedType] || toastTitles.default).trim();
    const toast = document.createElement('div');
    toast.className = `toast toast-${normalizedType} ${normalizedType}`;
    toast.dataset.toastType = normalizedType;
    toast.dataset.toastTitle = heading;
    toast.dataset.dismissAfter = String(Number(duration) || toastDurations[normalizedType] || toastDurations.default);
    toast.textContent = text || heading;
    getToastStack().appendChild(toast);
    const enhanced = enhanceToast(toast);
    const titleNode = enhanced.querySelector('.toast-title');
    const messageNode = enhanced.querySelector('.toast-message');
    if (titleNode) titleNode.textContent = heading;
    if (messageNode) messageNode.textContent = text;
    return enhanced;
  };

  document.querySelectorAll('.toast').forEach(enhanceToast);

  const toastObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        if (node.matches('.toast')) enhanceToast(node);
        node.querySelectorAll?.('.toast').forEach(enhanceToast);
      });
    });
  });
  toastObserver.observe(document.body, { childList: true, subtree: true });

  window.showNotification = showNotification;
  window.showToast = showToast;
  window.notify = (type, message, options) => showNotification(type, message, options);

  document.querySelectorAll('form').forEach((form) => {
    if (form.hasAttribute('data-no-loading')) {
      return;
    }
    const submit = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', () => {
      if (submit) {
        submit.dataset.originalText = submit.textContent;
        submit.textContent = submit.dataset.loadingText || form.dataset.loadingText || 'Working...';
        submit.disabled = true;
      }
      form.classList.add('loading');
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      const message = el.getAttribute('data-confirm') || 'Are you sure?';
      const requiredText = (el.getAttribute('data-confirm-text') || '').trim();
      if (requiredText) {
        const entered = window.prompt(`${message}\n\nType ${requiredText} to continue:`, '');
        if ((entered || '').trim() !== requiredText) {
          event.preventDefault();
          return;
        }
        return;
      }
      if (!confirm(message)) {
        event.preventDefault();
      }
    });
  });

  const clientNoteModal = document.querySelector('[data-client-note-modal]');
  if (clientNoteModal instanceof HTMLElement) {
    const noteText = clientNoteModal.querySelector('[data-client-note-text]');
    const closeButtons = clientNoteModal.querySelectorAll('[data-client-note-close]');
    let lastNoteTrigger = null;
    const formatAppointmentNote = (message) => {
      const text = (message || 'No notes were added for this appointment.').trim();
      return text.replace(/([.!?])(?=[A-Z])/g, '$1 ');
    };

    const openClientNoteModal = (message, trigger) => {
      if (noteText) {
        noteText.textContent = formatAppointmentNote(message);
      }
      lastNoteTrigger = trigger || null;
      clientNoteModal.classList.add('is-open');
      clientNoteModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('has-modal-open');
      const closeButton = clientNoteModal.querySelector('[data-client-note-close]');
      closeButton?.focus();
    };

    const closeClientNoteModal = () => {
      clientNoteModal.classList.remove('is-open');
      clientNoteModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('has-modal-open');
      lastNoteTrigger?.focus();
    };

    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-client-note-open]');
      if (!(trigger instanceof HTMLElement)) return;
      event.preventDefault();
      openClientNoteModal(trigger.dataset.note || '', trigger);
    });

    closeButtons.forEach((button) => {
      button.addEventListener('click', closeClientNoteModal);
    });

    clientNoteModal.addEventListener('click', (event) => {
      if (event.target === clientNoteModal) {
        closeClientNoteModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && clientNoteModal.classList.contains('is-open')) {
        closeClientNoteModal();
      }
    });
  }

  const appointmentForm = document.querySelector('[data-client-appointment-form]');
  if (appointmentForm instanceof HTMLFormElement) {
    const page = appointmentForm.closest('[data-client-appointment-page]');
    const lawyerSelect = appointmentForm.querySelector('[data-appointment-lawyer-select]');
    const dateInput = appointmentForm.querySelector('[data-appointment-date]');
    const timeInput = appointmentForm.querySelector('[data-appointment-time]');
    const typeInput = appointmentForm.querySelector('[data-appointment-type]');
    const summaryLawyer = appointmentForm.querySelector('[data-appointment-summary-lawyer]');
    const summaryMeta = appointmentForm.querySelector('[data-appointment-summary-meta]');
    const pickerModal = page?.querySelector('[data-appointment-picker-modal]');
    const pickerOpen = page?.querySelector('[data-appointment-picker-open]');
    const pickerClose = page?.querySelector('[data-appointment-picker-close]');
    const calendarGrid = page?.querySelector('[data-calendar-grid]');
    const calendarTitle = page?.querySelector('[data-calendar-title]');
    const selectedDateLabel = page?.querySelector('[data-selected-date-label]');
    const sessionArea = page?.querySelector('[data-session-area]');
    const timeArea = page?.querySelector('[data-time-area]');
    const timeGrid = page?.querySelector('[data-time-grid]');
    const pickerLabel = page?.querySelector('[data-appointment-picker-label]');
    const pickerMeta = page?.querySelector('[data-appointment-picker-meta]');
    const pickerLawyer = page?.querySelector('[data-appointment-picker-lawyer]');
    const morningCount = page?.querySelector('[data-morning-count]');
    const afternoonCount = page?.querySelector('[data-afternoon-count]');
    const prevMonth = page?.querySelector('[data-calendar-prev]');
    const nextMonth = page?.querySelector('[data-calendar-next]');
    const sessionButtons = [...(page?.querySelectorAll('[data-session]') || [])];
    const defaultCapacity = 10;
    let calendarCursor = new Date(); calendarCursor.setDate(1);
    let monthData = { days: {} };
    let selectedSession = '';

    const pad = (n) => String(n).padStart(2, '0');
    const localDateKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const monthKey = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
    const parseLocalDate = (value) => { if (!value) return null; const [y,m,d] = value.split('-').map(Number); return new Date(y,m-1,d); };
    const formatDate = (value) => { const d=parseLocalDate(value); return d ? d.toLocaleDateString(undefined,{month:'long',day:'numeric',year:'numeric'}) : ''; };
    const formatShortDate = (value) => { const d=parseLocalDate(value); return d ? d.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}) : ''; };
    const formatTime = (value) => { if (!value) return ''; const d=new Date(`2000-01-01T${value}`); return Number.isNaN(d.getTime()) ? value : d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit'}); };
    const todayKey = localDateKey(new Date());

    const updateAppointmentSummary = () => {
      const selectedOption = lawyerSelect?.selectedOptions?.[0];
      const lawyerLabel = selectedOption && selectedOption.value ? selectedOption.textContent.trim() : 'Choose a lawyer';
      const specialization = selectedOption?.dataset?.specialization || 'General Practice';
      const dateLabel = formatShortDate(dateInput?.value || ''); const timeLabel = formatTime(timeInput?.value || '');
      const typeLabel = typeInput?.value || 'Consultation';
      if (summaryLawyer) summaryLawyer.textContent = lawyerLabel;
      if (summaryMeta) summaryMeta.textContent = dateLabel && timeLabel ? `${typeLabel} with ${specialization} on ${dateLabel} at ${timeLabel}. The request will stay pending until the lawyer confirms it.` : 'Select a date and time to preview this request.';
      if (pickerLabel) pickerLabel.textContent = dateLabel && timeLabel ? `${dateLabel} at ${timeLabel}` : 'Choose date and time';
      if (pickerMeta) pickerMeta.textContent = dateLabel && timeLabel ? 'Tap to change your consultation schedule.' : 'Choose an available date, session, and time.';
      if (pickerLawyer) pickerLawyer.textContent = lawyerLabel === 'Choose a lawyer' ? 'Choose a lawyer first.' : `Availability for ${lawyerLabel}`;
    };

    const fetchMonthAvailability = async () => {
      const lawyerId = lawyerSelect?.value || '';
      if (!lawyerId) { monthData = {days:{}}; return; }
      try {
        const url = new URL(window.location.href); url.search=''; url.searchParams.set('availability','1'); url.searchParams.set('lawyer_id',lawyerId); url.searchParams.set('month',monthKey(calendarCursor));
        const response = await fetch(url.toString(), {credentials:'same-origin',headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Availability request failed');
        const data = await response.json(); if (data.ok) monthData = data;
      } catch (error) { console.warn('Unable to load appointment availability.', error); monthData={days:{}}; }
    };

    const getDayData = (key) => monthData.days?.[key] || {available:false,morning:{booked:0,capacity:0,start:null,end:null},afternoon:{booked:0,capacity:0,start:null,end:null},booked_times:[],blocked_reason:''};
    const buildTimes = (start, end) => {
      if (!start || !end) return [];
      const [sh,sm]=start.split(':').map(Number), [eh,em]=end.split(':').map(Number); let current=sh*60+sm, finish=eh*60+em, out=[];
      while (current < finish) { out.push(`${pad(Math.floor(current/60))}:${pad(current%60)}`); current += 30; }
      return out;
    };
    const updateSessionCards = () => {
      const data=getDayData(dateInput?.value || '');
      [['morning',morningCount],['afternoon',afternoonCount]].forEach(([key,count])=>{
        const item=data[key] || {}; const cap=Number(item.capacity||0), booked=Number(item.booked||0), remaining=Math.max(0,cap-booked);
        if(count) count.textContent=cap>0 ? `${remaining}/${cap} available` : 'Not available';
        const button=sessionButtons.find(b=>b.dataset.session===key); if(button){ const open=Boolean(data.available && cap>0 && remaining>0 && item.start && item.end); button.disabled=!open; button.classList.toggle('is-full',!open); button.classList.toggle('is-selected',selectedSession===key); const small=button.querySelector('small'); if(small) small.textContent=item.start && item.end ? `${formatTime(item.start)} – ${formatTime(item.end)}` : 'Not available'; }
      });
    };
    const renderTimes = () => {
      if (!(timeGrid instanceof HTMLElement)) return; const data=getDayData(dateInput?.value||''); const item=data[selectedSession]; const times=buildTimes(item?.start,item?.end); const booked=new Set(data.booked_times||[]); timeGrid.innerHTML='';
      times.forEach(time=>{ const button=document.createElement('button'); button.type='button'; button.className='client-appointment-time-button'; button.textContent=formatTime(time); button.disabled=booked.has(time); if(timeInput?.value===time) button.classList.add('is-selected'); if(button.disabled){button.classList.add('is-booked');button.title='Already booked';} button.addEventListener('click',()=>{if(button.disabled)return; timeInput.value=time; updateAppointmentSummary(); renderTimes();}); timeGrid.appendChild(button); });
      if(timeArea) timeArea.hidden=times.length===0;
    };
    const renderCalendar = async () => {
      if (!(calendarGrid instanceof HTMLElement) || !(calendarTitle instanceof HTMLElement)) return; await fetchMonthAvailability(); calendarTitle.textContent=calendarCursor.toLocaleDateString(undefined,{month:'long',year:'numeric'}); calendarGrid.innerHTML='';
      const year=calendarCursor.getFullYear(), month=calendarCursor.getMonth(), firstDay=new Date(year,month,1).getDay(), days=new Date(year,month+1,0).getDate();
      for(let i=0;i<firstDay;i++){const blank=document.createElement('span');blank.className='client-appointment-calendar-day is-empty';calendarGrid.appendChild(blank);}
      for(let day=1;day<=days;day++){
        const date=new Date(year,month,day), key=localDateKey(date), info=getDayData(key), button=document.createElement('button'); button.type='button'; button.className='client-appointment-calendar-day'; button.textContent=String(day); button.dataset.date=key;
        const isPast=key<todayKey, canBook=Boolean(info.available && ((info.morning?.capacity>info.morning?.booked && info.morning?.start) || (info.afternoon?.capacity>info.afternoon?.booked && info.afternoon?.start)));
        button.disabled=isPast || !canBook || !lawyerSelect?.value; if(isPast) button.classList.add('is-past'); if(info.available && canBook) button.classList.add('is-available'); else if(info.blocked_reason) button.classList.add('is-blocked'); else button.classList.add('is-unavailable'); if(key===todayKey) button.classList.add('is-today'); if(key===dateInput?.value) button.classList.add('is-selected');
        button.title=isPast?'Past date':info.blocked_reason?info.blocked_reason:!info.available?'Lawyer unavailable':canBook?'Available':'Fully booked';
        button.addEventListener('click',async()=>{dateInput.value=key;timeInput.value='';selectedSession='';if(selectedDateLabel)selectedDateLabel.textContent=formatDate(key);if(sessionArea)sessionArea.hidden=false;if(timeArea)timeArea.hidden=true;updateSessionCards();renderCalendar();updateAppointmentSummary();}); calendarGrid.appendChild(button);
      }
      if(dateInput?.value){updateSessionCards(); if(selectedSession)renderTimes();}
    };
    const openPicker=async()=>{if(!lawyerSelect?.value){lawyerSelect?.focus();return;}const selected=parseLocalDate(dateInput?.value||todayKey);if(selected)calendarCursor=new Date(selected.getFullYear(),selected.getMonth(),1);pickerModal?.classList.add('is-open');pickerModal?.setAttribute('aria-hidden','false');document.body.classList.add('modal-open');await renderCalendar();};
    const closePicker=()=>{pickerModal?.classList.remove('is-open');pickerModal?.setAttribute('aria-hidden','true');document.body.classList.remove('modal-open');};
    pickerOpen?.addEventListener('click',openPicker); pickerClose?.addEventListener('click',closePicker); pickerModal?.addEventListener('click',e=>{if(e.target===pickerModal)closePicker();});
    prevMonth?.addEventListener('click',async()=>{calendarCursor=new Date(calendarCursor.getFullYear(),calendarCursor.getMonth()-1,1);await renderCalendar();}); nextMonth?.addEventListener('click',async()=>{calendarCursor=new Date(calendarCursor.getFullYear(),calendarCursor.getMonth()+1,1);await renderCalendar();});
    sessionButtons.forEach(button=>button.addEventListener('click',()=>{if(button.disabled)return;selectedSession=button.dataset.session||'';timeInput.value='';updateSessionCards();renderTimes();}));
    lawyerSelect?.addEventListener('change',async()=>{dateInput.value='';timeInput.value='';selectedSession='';if(sessionArea)sessionArea.hidden=true;if(timeArea)timeArea.hidden=true;await fetchMonthAvailability();updateAppointmentSummary();});
    typeInput?.addEventListener('change',updateAppointmentSummary); typeInput?.addEventListener('input',updateAppointmentSummary); updateAppointmentSummary();
  }

  const apiBase = document.body.dataset.apiBase;
  if (apiBase) {
    fetch(`${apiBase.replace(/\/$/, '')}/health`, { credentials: 'omit' })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && data.status) {
          console.debug('LEXSHIELD API health:', data.status);
        }
      })
      .catch(() => {
        console.debug('LEXSHIELD API is not reachable from the browser right now.');
      });
  }

  const appointmentBoard = document.querySelector('[data-appointment-board]');
  if (appointmentBoard) {
    const endpoint = appointmentBoard.dataset.endpoint || window.location.href;
    const results = appointmentBoard.querySelector('[data-appointment-results]');
    const pagination = appointmentBoard.querySelector('[data-appointment-pagination]');
    const summary = appointmentBoard.querySelector('[data-appointment-summary]');
    const filters = appointmentBoard.querySelector('[data-appointment-filters]');
    const searchInput = filters ? filters.querySelector('[data-appointment-search]') : null;
    const statusInput = filters ? filters.querySelector('[data-appointment-status]') : null;
    const pageInput = filters ? filters.querySelector('[data-appointment-page-input]') : null;
    let searchTimer = null;
    let activeRequest = 0;

    const readState = () => ({
      q: searchInput ? searchInput.value.trim() : '',
      status: statusInput ? statusInput.value : 'all',
      page: pageInput ? pageInput.value : '1',
    });

    const readStateFromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        q: params.get('q') || '',
        status: params.get('status') || 'all',
        page: params.get('page') || '1',
      };
    };

    const syncInputs = (state) => {
      if (searchInput && searchInput.value !== (state.q || '')) searchInput.value = state.q || '';
      if (statusInput && statusInput.value !== (state.status || 'all')) statusInput.value = state.status || 'all';
      if (pageInput) pageInput.value = String(state.page || '1');
    };

    const syncUrl = (state, mode) => {
      const url = new URL(window.location.href);
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      url.searchParams.delete('format');
      if (mode === 'push') {
        history.pushState({}, '', url);
      } else {
        history.replaceState({}, '', url);
      }
    };

    const buildUrl = (state) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('format', 'json');
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      return url;
    };

    const setBusy = (isBusy) => {
      appointmentBoard.classList.toggle('is-loading', isBusy);
      if (results) {
        results.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      }
    };

    const fetchAppointments = async (state, options = {}) => {
      if (!endpoint) return;
      const requestId = ++activeRequest;
      const nextState = {
        q: state.q || '',
        status: state.status || 'all',
        page: state.page || '1',
      };
      const url = buildUrl(nextState);
      setBusy(true);
      try {
        const response = await fetch(url.toString(), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error('Request failed');
        }
        const data = await response.json();
        if (requestId !== activeRequest) {
          return;
        }
        if (results && data.resultsHtml !== undefined) {
          results.innerHTML = data.resultsHtml;
        }
        if (pagination && data.paginationHtml !== undefined) {
          pagination.innerHTML = data.paginationHtml;
        }
        if (summary && data.summaryText !== undefined) {
          summary.textContent = data.summaryText;
        }
        const syncedState = data.state || nextState;
        syncInputs(syncedState);
        syncUrl(syncedState, options.push ? 'push' : 'replace');
      } catch (error) {
        console.debug('Unable to refresh appointments right now.');
      } finally {
        if (requestId === activeRequest) {
          setBusy(false);
        }
      }
    };

    if (filters) {
      filters.addEventListener('submit', (event) => {
        event.preventDefault();
        fetchAppointments({
          ...readState(),
          page: '1',
        }, { push: true });
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
          fetchAppointments({
            ...readState(),
            page: '1',
          }, { push: false });
        }, 220);
      });
    }

    if (statusInput) {
      statusInput.addEventListener('change', () => {
        fetchAppointments({
          ...readState(),
          page: '1',
        }, { push: true });
      });
    }

    if (pageInput) {
      pageInput.addEventListener('change', () => {
        fetchAppointments(readState(), { push: true });
      });
    }

    appointmentBoard.addEventListener('click', (event) => {
      const pageLink = event.target.closest('[data-appointment-page]');
      if (!pageLink) return;
      if (pageLink.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        return;
      }
      if (pageLink.tagName !== 'A') {
        return;
      }
      event.preventDefault();
      fetchAppointments({
        ...readState(),
        page: pageLink.dataset.page || '1',
      }, { push: true });
    });

    window.addEventListener('popstate', () => {
      const state = readStateFromUrl();
      syncInputs(state);
      fetchAppointments(state, { push: false });
    });
  }

})();
