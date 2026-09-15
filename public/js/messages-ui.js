(() => {
  const setModalInertSiblings = (modal, enabled) => {
    if (!(modal instanceof HTMLElement)) return;
    let current = modal;
    while (current && current !== document.body) {
      const parent = current.parentElement;
      if (!parent) break;
      Array.from(parent.children).forEach((sibling) => {
        if (!(sibling instanceof HTMLElement) || sibling === current) return;
        const currentCount = Number(sibling.dataset.lexInertCount || '0');
        if (enabled) {
          sibling.dataset.lexInertCount = String(currentCount + 1);
          sibling.inert = true;
          return;
        }
        const nextCount = Math.max(0, currentCount - 1);
        if (nextCount === 0) {
          delete sibling.dataset.lexInertCount;
          sibling.inert = false;
        } else {
          sibling.dataset.lexInertCount = String(nextCount);
        }
      });
      current = parent;
    }
  };

  const chatShell = document.querySelector('[data-chat-shell]');
  const closeChatMoreMenus = (except = null) => {
    document.querySelectorAll('[data-chat-more-menu]').forEach((menu) => {
      if (!(menu instanceof HTMLElement) || menu === except) return;
      menu.hidden = true;
      const toggle = menu.parentElement?.querySelector('[data-chat-more-toggle]');
      if (toggle instanceof HTMLElement) toggle.setAttribute('aria-expanded', 'false');
    });
  };

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-chat-more-toggle]');
    if (toggle) {
      event.preventDefault();
      event.stopPropagation();
      const wrapper = toggle.closest('.chat-header-more');
      const menu = wrapper?.querySelector('[data-chat-more-menu]');
      if (!(menu instanceof HTMLElement)) return;
      const willOpen = menu.hidden;
      closeChatMoreMenus(willOpen ? menu : null);
      menu.hidden = !willOpen;
      toggle.setAttribute('aria-expanded', String(willOpen));
      return;
    }
    if (event.target.closest('.chat-more-item')) { closeChatMoreMenus(); return; }
    if (!event.target.closest('.chat-header-more')) closeChatMoreMenus();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeChatMoreMenus();
  });

  const closeConversationActionMenus = (except = null) => {
    document.querySelectorAll('[data-conversation-more-menu]').forEach((menu) => {
      if (!(menu instanceof HTMLElement) || menu === except) return;
      menu.hidden = true;
      const toggle = menu.parentElement?.querySelector('[data-conversation-more-toggle]');
      if (toggle instanceof HTMLElement) toggle.setAttribute('aria-expanded', 'false');
    });
  };

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-conversation-more-toggle]');
    if (toggle) {
      event.preventDefault();
      event.stopPropagation();
      const wrapper = toggle.closest('[data-conversation-more]');
      const menu = wrapper?.querySelector('[data-conversation-more-menu]');
      if (!(menu instanceof HTMLElement)) return;
      const willOpen = menu.hidden;
      closeConversationActionMenus(willOpen ? menu : null);
      menu.hidden = !willOpen;
      toggle.setAttribute('aria-expanded', String(willOpen));
      return;
    }

    const deleteForm = event.target.closest('.conversation-delete-form');
    if (deleteForm && event.target.closest('button')) {
      const confirmed = window.confirm('Delete this conversation from your view? This cannot be undone.');
      if (!confirmed) {
        event.preventDefault();
        event.stopPropagation();
        closeConversationActionMenus();
      }
      return;
    }

    if (!event.target.closest('[data-conversation-more]')) closeConversationActionMenus();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeConversationActionMenus();
  });

  document.querySelectorAll('.messages-layout, .admin-messages-layout').forEach((layout) => {
    if (window.matchMedia('(max-width: 767px)').matches) {
      const hasActiveConversation = !!layout.querySelector('.conversation-item.is-active');
      layout.classList.toggle('chat-mobile-show-conversation', hasActiveConversation);
      layout.classList.toggle('chat-mobile-show-list', !hasActiveConversation);
    }
  });

  document.querySelectorAll('[data-conversation-item] .conversation-item-link').forEach((link) => {
    link.addEventListener('click', () => {
      const layout = link.closest('.messages-layout, .admin-messages-layout');
      if (layout && window.matchMedia('(max-width: 767px)').matches) {
        layout.classList.remove('chat-mobile-show-list');
        layout.classList.add('chat-mobile-show-conversation');
      }
    });
  });

  document.querySelectorAll('[data-mobile-conversation-back]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.messages-layout, .admin-messages-layout').forEach((layout) => {
        layout.classList.remove('chat-mobile-show-conversation');
        layout.classList.add('chat-mobile-show-list');
      });
    });
  });

  let genericModalReturnFocus = null;
  let phishingScanController = null;
  const openModal = (modal) => {
    if (!modal) return;
    genericModalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setModalInertSiblings(modal, true);
    const firstField = modal.querySelector('input, select, textarea, button:not([data-modal-close])');
    if (firstField) {
      setTimeout(() => firstField.focus(), 50);
    }
  };

  const closeModal = (modal) => {
    if (!modal) return;
    const active = document.activeElement;
    if (active instanceof HTMLElement && modal.contains(active)) {
      active.blur();
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    setModalInertSiblings(modal, false);
    if (genericModalReturnFocus instanceof HTMLElement && document.contains(genericModalReturnFocus)) {
      setTimeout(() => genericModalReturnFocus.focus(), 0);
    }
    genericModalReturnFocus = null;
  };

  const renderAttachmentPreview = (container, files, emptyText = 'No file selected') => {
    if (!container) return;
    container.innerHTML = '';
    if (!files || !files.length) {
      container.textContent = emptyText;
      return;
    }
    files.forEach((file) => {
      const item = document.createElement('div');
      item.className = 'attachment-chip';
      item.textContent = `${file.name} - ${Math.max(1, Math.round(file.size / 1024))} KB`;
      container.appendChild(item);
    });
  };

  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const PhishingResult = {
    labels: {
      safe: 'Safe ✅',
      suspicious: 'Suspicious ⚠️',
      phishing: 'Phishing 🚨',
    },
    render(container, payload) {
      if (!container) return;
      const status = ['safe', 'suspicious', 'phishing'].includes(payload?.status) ? payload.status : 'suspicious';
      const score = Number.isFinite(Number(payload?.score)) ? Number(payload.score) : null;
      const message = escapeHtml(payload?.message || 'The scan completed, but no details were returned.');
      const findings = Array.isArray(payload?.findings)
        ? payload.findings.filter((finding) => typeof finding === 'string' && finding.trim() !== '')
        : [];
      const redirectCount = Number.isFinite(Number(payload?.redirect_count)) ? Number(payload.redirect_count) : 0;
      const finalUrl = typeof payload?.final_url === 'string' ? payload.final_url.trim() : '';
      const redirectHtml = redirectCount > 0 && finalUrl
        ? `<span>Final URL: ${escapeHtml(finalUrl)}</span><span>Redirects followed: ${redirectCount}</span>`
        : '';
      const findingsHtml = findings.length
        ? `<ul>${findings.map((finding) => `<li>${escapeHtml(finding)}</li>`).join('')}</ul>`
        : '';
      container.className = `phishing-detector-result is-${status}`;
      container.innerHTML = `
        <strong>${this.labels[status]}</strong>
        ${score === null ? '' : `<span>Score: ${score}</span>`}
        ${redirectHtml}
        <p>${message}</p>
        ${findingsHtml}
      `;
      container.hidden = false;
    },
    clear(container) {
      if (!container) return;
      container.hidden = true;
      container.className = 'phishing-detector-result';
      container.innerHTML = '';
    },
  };

  const PhishingInput = {
    read(input) {
      return (input?.value || '').trim();
    },
    validate(value) {
      try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
      } catch (error) {
        return false;
      }
    },
  };

  const PhishingButton = {
    setLoading(form, isLoading) {
      const buttons = form.querySelectorAll('button');
      const spinner = form.querySelector('[data-phishing-submit] .phishing-spinner');
      const label = form.querySelector('[data-phishing-submit-label]');
      buttons.forEach((button) => {
        button.disabled = isLoading;
        button.setAttribute('aria-disabled', String(isLoading));
      });
      if (spinner) spinner.hidden = !isLoading;
      if (label) label.textContent = isLoading ? 'Scanning...' : 'Scan';
      form.classList.toggle('loading', isLoading);
    },
  };

  const PhishingModal = {
    reset(modal) {
      if (!modal || modal.id !== 'phishingDetectorModal') return;
      const form = modal.querySelector('[data-phishing-form]');
      const input = modal.querySelector('[data-phishing-input]');
      const error = modal.querySelector('[data-phishing-error]');
      const result = modal.querySelector('[data-phishing-result]');
      const submit = modal.querySelector('[data-phishing-submit]');
      const label = modal.querySelector('[data-phishing-submit-label]');
      if (phishingScanController) {
        phishingScanController.abort();
        phishingScanController = null;
      }
      if (form) {
        form.dataset.scanCancelled = '1';
        PhishingButton.setLoading(form, false);
      }
      if (input) input.value = '';
      if (input) input.disabled = false;
      if (submit) {
        submit.disabled = false;
        submit.removeAttribute('aria-disabled');
      }
      if (label) label.textContent = 'Scan';
      if (error) {
        error.textContent = '';
        error.hidden = true;
      }
      PhishingResult.clear(result);
    },
    showError(node, message) {
      if (!node) return;
      node.textContent = message;
      node.hidden = false;
    },
  };

  const resetNewMessageModal = (modal) => {
    if (!modal || modal.id !== 'newMessageModal') return;
    const fileInput = modal.querySelector('[data-modal-attachment-input]');
    const fileName = modal.querySelector('[data-modal-attachment-name]');
    const errorBox = modal.querySelector('[data-modal-errors]');
    if (fileInput) fileInput.value = '';
    renderAttachmentPreview(fileName, []);
    if (errorBox) {
      errorBox.textContent = '';
      errorBox.hidden = true;
    }
  };

  const resetModalState = (modal) => {
    resetNewMessageModal(modal);
    PhishingModal.reset(modal);
  };

  if (chatShell) {
    const scrollArea = chatShell.querySelector('[data-chat-scroll]');
    if (scrollArea) {
      scrollArea.scrollTop = scrollArea.scrollHeight;
    }

    const searchInput = chatShell.querySelector('[data-conversation-search]');
    const items = Array.from(chatShell.querySelectorAll('[data-conversation-item]'));
    const tabs = Array.from(chatShell.querySelectorAll('[data-filter-tab]'));
    const groups = Array.from(chatShell.querySelectorAll('.conversation-group'));
    let currentFilter = 'all';
    const applyFilter = (filter) => {
      currentFilter = filter;
      items.forEach((item) => {
        const unread = item.dataset.unread === '1';
        const important = item.dataset.important === '1';
        const archived = item.dataset.archived === '1';
        const show =
          filter === 'all'
            ? !archived
            : filter === 'unread'
              ? unread && !archived
              : filter === 'important'
                ? important && !archived
                : filter === 'archived'
                  ? archived
                  : true;
        if (searchInput && searchInput.value.trim() !== '') {
          const query = searchInput.value.trim().toLowerCase();
          item.hidden = !show || !item.textContent.toLowerCase().includes(query);
        } else {
          item.hidden = !show;
        }
      });
      groups.forEach((group) => {
        const visibleItems = Array.from(group.querySelectorAll('[data-conversation-item]')).some((item) => !item.hidden);
        group.hidden = !visibleItems;
      });
    };
    if (searchInput && items.length) {
      searchInput.addEventListener('input', () => applyFilter(currentFilter));
    }
    if (tabs.length) {
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          tabs.forEach((t) => t.classList.remove('is-active'));
          tab.classList.add('is-active');
          const filter = tab.dataset.filterTab || 'all';
          applyFilter(filter);
          const url = new URL(window.location.href);
          if (filter === 'all') {
            url.searchParams.delete('filter');
          } else {
            url.searchParams.set('filter', filter);
          }
          window.history.replaceState({}, '', url);
        });
      });
      const requestedFilter = new URLSearchParams(window.location.search).get('filter') || 'all';
      const activeFilter = ['all', 'unread', 'important', 'archived'].includes(requestedFilter) ? requestedFilter : 'all';
      tabs.forEach((tab) => tab.classList.toggle('is-active', (tab.dataset.filterTab || 'all') === activeFilter));
      applyFilter(activeFilter);
    }

    document.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const modal = button.closest('[data-modal]');
        closeModal(modal);
        resetModalState(modal);
      });
    });

    document.querySelectorAll('[data-modal]').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal(modal);
          resetModalState(modal);
        }
      });
    });

    document.addEventListener('click', (event) => {
      const openTrigger = event.target.closest('[data-modal-open]');
      if (openTrigger) {
        event.preventDefault();
        const target = document.getElementById(openTrigger.dataset.modalOpen || '');
        if (!target) return;
        if (target.id === 'conversationInfoModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-info-name]', data.infoName);
          setText('[data-info-role]', data.infoRole);
          setText('[data-info-status]', data.infoStatus);
          setText('[data-info-case]', data.infoCase);
          setText('[data-info-id]', data.infoId);
          setText('[data-info-created]', data.infoCreated);
          setText('[data-info-activity]', data.infoActivity);
          const avatar = target.querySelector('[data-info-avatar]');
          const avatarImg = target.querySelector('[data-info-avatar-img]');
          const avatarText = target.querySelector('[data-info-avatar-text]');
          const avatarUrl = data.infoAvatarUrl || '';
          if (avatarImg instanceof HTMLImageElement && avatarText) {
            if (avatarUrl) {
              avatarImg.src = avatarUrl;
              avatarImg.hidden = false;
              avatarText.hidden = true;
            } else {
              avatarImg.removeAttribute('src');
              avatarImg.hidden = true;
              avatarText.textContent = (data.infoName || '?').replace(/\s+/g, '').slice(0, 2).toUpperCase();
              avatarText.hidden = false;
            }
          } else if (avatar) {
            avatar.textContent = (data.infoName || '?').slice(0, 2).toUpperCase();
          }
        } else if (target.id === 'newMessageModal') {
          const data = openTrigger.dataset;
          const caseInput = target.querySelector('[data-default-case-input]');
          const recipientSelect = target.querySelector('[data-recipient-select]');
          const recipientRole = target.querySelector('[data-recipient-role]');
          const fileInput = target.querySelector('[data-modal-attachment-input]');
          const fileName = target.querySelector('[data-modal-attachment-name]');
          const errorBox = target.querySelector('[data-modal-errors]');
          if (caseInput && data.defaultCase) {
            caseInput.value = data.defaultCase;
          }
          if (recipientSelect) {
            const defaultRecipient = data.defaultRecipient || '';
            const hasDefaultRecipient = defaultRecipient !== '' && defaultRecipient !== '0' && Array.from(recipientSelect.options).some((option) => option.value === defaultRecipient && !option.disabled);
            recipientSelect.value = hasDefaultRecipient ? defaultRecipient : '';
          }
          if (recipientRole && data.defaultRole) {
            recipientRole.value = data.defaultRole;
          }
          if (recipientSelect) {
            recipientSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (fileInput) fileInput.value = '';
          renderAttachmentPreview(fileName, []);
          if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
          }
        } else if (target.id === 'phishingDetectorModal') {
          PhishingModal.reset(target);
        } else if (target.id === 'profileModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-profile-name]', data.profileName);
          setText('[data-profile-role]', data.profileRole);
          setText('[data-profile-status]', data.profileStatus);
          setText('[data-profile-case]', data.profileCase);
          setText('[data-profile-email]', data.profileEmail || 'Not provided');
          setText('[data-profile-note]', data.profileNote);
          const avatar = target.querySelector('[data-profile-avatar]');
          if (avatar) avatar.textContent = (data.profileAvatar || '?').slice(0, 2).toUpperCase();
        }
        openModal(target);
        return;
      }

      const closeTrigger = event.target.closest('[data-modal-close]');
      if (closeTrigger) {
        event.preventDefault();
        const modal = closeTrigger.closest('[data-modal]');
        closeModal(modal);
        resetModalState(modal);
        return;
      }

      const overlay = event.target.closest('[data-modal]');
      if (overlay && event.target === overlay) {
        closeModal(overlay);
        resetModalState(overlay);
      }
    });

    const phishingForm = document.querySelector('[data-phishing-form]');
    if (phishingForm) {
      phishingForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (phishingForm.dataset.scanning === '1') {
          return;
        }
        const input = phishingForm.querySelector('[data-phishing-input]');
        const error = phishingForm.querySelector('[data-phishing-error]');
        const result = phishingForm.querySelector('[data-phishing-result]');
        const endpoint = phishingForm.dataset.endpoint || '';
        const url = PhishingInput.read(input);
        const scanController = new AbortController();
        let timeoutId = null;

        if (error) {
          error.textContent = '';
          error.hidden = true;
        }
        PhishingResult.clear(result);

        if (!PhishingInput.validate(url)) {
          PhishingModal.showError(error, 'Enter a valid http or https URL.');
          input?.focus();
          return;
        }

        PhishingButton.setLoading(phishingForm, true);
        phishingForm.dataset.scanning = '1';
        phishingForm.dataset.scanCancelled = '0';
        phishingScanController = scanController;
        timeoutId = window.setTimeout(() => scanController.abort(), 8000);
        try {
          if (!endpoint) {
            throw new Error('The phishing scanner endpoint is not configured.');
          }
          const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-Token': phishingForm.querySelector('input[name=csrf_token]')?.value || '',
            },
            body: JSON.stringify({ url }),
            signal: scanController.signal,
          });
          const data = await response.json().catch(() => {
            throw new Error('The phishing scanner returned an invalid response.');
          });
          if (!response.ok) {
            throw new Error(data?.message || 'Unable to scan the URL right now.');
          }
          PhishingResult.render(result, data);
        } catch (scanError) {
          if (scanController.signal.aborted && phishingForm.dataset.scanCancelled === '1') {
            return;
          }
          let message = scanError.message || 'Unable to scan the URL right now.';
          if (scanError instanceof TypeError) {
            message = 'Unable to reach the phishing scanner. Please try again.';
          } else if (scanError?.name === 'AbortError') {
            message = 'The scan took too long. Please try again.';
          }
          PhishingModal.showError(error, message);
        } finally {
          if (timeoutId) {
            window.clearTimeout(timeoutId);
          }
          if (phishingScanController === scanController) {
            phishingScanController = null;
          }
          delete phishingForm.dataset.scanning;
          PhishingButton.setLoading(phishingForm, false);
        }
      });
    }
  }

  clientDeleteModal = document.querySelector('[data-client-delete-modal]');
  if (clientDeleteModal) {
    const deleteForm = clientDeleteModal.querySelector('[data-client-delete-form]');
    const deleteNameField = clientDeleteModal.querySelector('[data-client-delete-name]');
    const deleteConfirmInput = clientDeleteModal.querySelector('[data-client-delete-confirm-text]');
    const deleteSubmit = clientDeleteModal.querySelector('[data-client-delete-submit]');
    const deleteError = clientDeleteModal.querySelector('[data-client-delete-error]');
    const deleteClientId = deleteForm ? deleteForm.querySelector('input[name="client_id"]') : null;
    const deleteConfirmationField = deleteForm ? deleteForm.querySelector('[data-client-delete-confirmation]') : null;
    let expectedClientName = '';

    const clearDeleteState = () => {
      expectedClientName = '';
      if (deleteNameField) deleteNameField.value = '';
      if (deleteConfirmInput) deleteConfirmInput.value = '';
      if (deleteConfirmationField) deleteConfirmationField.value = '';
      if (deleteError) deleteError.textContent = '';
    };

    const validateDeleteText = () => {
      const entered = (deleteConfirmInput?.value || '').trim();
      const normalized = entered.replace(/\s+/g, ' ').trim();
      const requiredPhrase = `${expectedClientName} DELETE`.trim();
      const matches = expectedClientName !== '' && normalized === requiredPhrase;
      if (deleteConfirmationField) {
        deleteConfirmationField.value = normalized;
      }
      if (deleteError) {
        deleteError.textContent = entered !== '' && !matches ? 'Type the exact client name followed by DELETE.' : '';
      }
      return matches;
    };

    document.querySelectorAll('[data-client-delete-open]').forEach((button) => {
      button.addEventListener('click', () => {
        if (deleteClientId) {
          deleteClientId.value = button.dataset.clientId || '';
        }
        clearDeleteState();
        expectedClientName = button.dataset.clientName || '';
        if (deleteNameField) {
          deleteNameField.value = expectedClientName;
        }
        openModal(clientDeleteModal);
        validateDeleteText();
        if (deleteConfirmInput) {
          setTimeout(() => deleteConfirmInput.focus(), 50);
        }
      });
    });

    clientDeleteModal.querySelectorAll('[data-client-delete-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(clientDeleteModal);
        clearDeleteState();
      });
    });

    clientDeleteModal.addEventListener('click', (event) => {
      if (event.target === clientDeleteModal) {
        closeModal(clientDeleteModal);
        clearDeleteState();
      }
    });

    if (deleteConfirmInput) {
      deleteConfirmInput.addEventListener('input', validateDeleteText);
      deleteConfirmInput.addEventListener('keyup', validateDeleteText);
      deleteConfirmInput.addEventListener('change', validateDeleteText);
    }

    if (deleteForm) {
      deleteForm.addEventListener('submit', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          if (deleteError && (deleteConfirmInput?.value || '').trim() === '') {
            deleteError.textContent = 'Type the client name followed by DELETE to confirm deletion.';
          }
        }
      });
    }

    if (deleteSubmit && deleteForm) {
      deleteSubmit.addEventListener('click', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        deleteForm.submit();
      });
    }
  }

  if (chatShell) {
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('[data-modal].is-open').forEach((modal) => {
        closeModal(modal);
        resetModalState(modal);
      });
      if (clientDeleteModal && clientDeleteModal.classList.contains('is-open')) {
        closeModal(clientDeleteModal);
      }
    }
  });

  const attachmentInput = chatShell.querySelector('[data-attachment-input]');
  const attachmentPreview = chatShell.querySelector('[data-attachment-preview]');

  // Voice-message recorder. Uses the existing attachment field so the normal
  // CSRF, validation, malware scanning, access control, and send flow remain intact.
  const setupVoiceRecorder = (shell) => {
    const form = shell?.querySelector('[data-chat-composer]');
    const tools = form?.querySelector('.composer-tools');
    const audioInput = form?.querySelector('[data-attachment-input]');
    const sendButton = form?.querySelector('button[type="submit"]');
    const composerRow = form?.querySelector('.composer-row');
    if (!form || !tools || !audioInput || tools.querySelector('[data-voice-record]')) return;

    const recordButton = document.createElement('button');
    recordButton.type = 'button';
    recordButton.className = 'icon-button ghost voice-record-button';
    recordButton.setAttribute('data-voice-record', '1');
    recordButton.setAttribute('aria-label', 'Record voice message');
    recordButton.title = 'Record voice message';
    recordButton.innerHTML = '<span class="voice-mic-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0h-2a7 7 0 0 0 6 6.92V21H8v2h8v-2h-3v-3.08A7 7 0 0 0 19 11h-2Z"/></svg></span>';
    tools.appendChild(recordButton);

    const panel = document.createElement('div');
    panel.className = 'voice-recording-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');
    panel.innerHTML = `
      <div class="voice-live-main">
        <span class="voice-recording-dot" aria-hidden="true"></span>
        <div class="voice-live-copy">
          <strong data-voice-status>Recording</strong>
          <span>Voice message in progress</span>
        </div>
      </div>
      <div class="voice-wave" data-voice-wave aria-hidden="true">
        <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
      </div>
      <div class="voice-live-time" data-voice-timer>00:00</div>
      <div class="voice-recording-actions">
        <button class="button button-secondary voice-cancel-button" type="button" data-voice-cancel>Cancel</button>
        <button class="button button-danger voice-stop-button" type="button" data-voice-stop><span aria-hidden="true"></span> Stop</button>
      </div>`;
    if (composerRow) composerRow.parentNode.insertBefore(panel, composerRow);
    else form.appendChild(panel);

    let recorder = null;
    let stream = null;
    let chunks = [];
    let timerId = null;
    let startedAt = 0;
    let cancelled = false;
    let recordedSeconds = 0;
    const maxSeconds = 180;

    const formatTime = (seconds) => {
      const safe = Math.max(0, Math.floor(seconds));
      return `${String(Math.floor(safe / 60)).padStart(2, '0')}:${String(safe % 60).padStart(2, '0')}`;
    };

    const cleanupStream = () => {
      if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
      }
      if (timerId) {
        window.clearInterval(timerId);
        timerId = null;
      }
    };

    const resetRecordingUI = () => {
      cleanupStream();
      panel.hidden = true;
      form.classList.remove('is-recording-active');
      if (composerRow) composerRow.hidden = false;
      recordButton.disabled = false;
      recordButton.classList.remove('is-recording');
      recordButton.setAttribute('aria-label', 'Record voice message');
      recordButton.title = 'Record voice message';
      if (sendButton) sendButton.disabled = false;
    };

    const clearVoiceAttachment = () => {
      audioInput.value = '';
      if (attachmentPreview) {
        attachmentPreview.hidden = true;
        attachmentPreview.innerHTML = '';
      }
    };

    const showVoiceAttachment = (blob, file) => {
      if (!attachmentPreview) return;
      attachmentPreview.innerHTML = '';
      const item = document.createElement('div');
      item.className = 'attachment-chip voice-attachment-chip';
      item.innerHTML = `
        <span class="voice-attachment-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0h-2a7 7 0 0 0 6 6.92V21H8v2h8v-2h-3v-3.08A7 7 0 0 0 19 11h-2Z"/></svg>
        </span>
        <span class="voice-attachment-copy">
          <strong>Voice message</strong>
          <span>${formatTime(recordedSeconds)} • ${Math.max(1, Math.round(blob.size / 1024))} KB</span>
        </span>
        <button type="button" class="voice-attachment-remove" aria-label="Remove voice message" title="Remove voice message">×</button>`;
      item.querySelector('.voice-attachment-remove')?.addEventListener('click', (event) => {
        event.preventDefault();
        clearVoiceAttachment();
      });
      attachmentPreview.appendChild(item);
      attachmentPreview.hidden = false;
    };

    const finishRecording = () => {
      cleanupStream();
      resetRecordingUI();

      if (cancelled || !chunks.length) {
        chunks = [];
        return;
      }

      const mimeType = recorder?.mimeType || chunks[0]?.type || 'audio/webm';
      const extension = mimeType.includes('mp4') ? 'm4a' : mimeType.includes('ogg') ? 'ogg' : mimeType.includes('mpeg') ? 'mp3' : mimeType.includes('wav') ? 'wav' : 'webm';
      const blob = new Blob(chunks, { type: mimeType });
      recordedSeconds = Math.max(1, Math.floor((Date.now() - startedAt) / 1000));
      chunks = [];

      try {
        const transfer = new DataTransfer();
        const file = new File([blob], `voice-message-${Date.now()}.${extension}`, { type: mimeType, lastModified: Date.now() });
        transfer.items.add(file);
        audioInput.files = transfer.files;
        showVoiceAttachment(blob, file);
      } catch (error) {
        showVoiceError('Your browser could not prepare the voice message. Please try again.');
      }
    };

    const stopRecording = (discard = false) => {
      cancelled = discard;
      if (recorder && recorder.state !== 'inactive') {
        recorder.stop();
      } else {
        resetRecordingUI();
      }
    };

    const showVoiceError = (message) => {
      let notice = form.querySelector('[data-voice-error]');
      if (!(notice instanceof HTMLElement)) {
        notice = document.createElement('div');
        notice.className = 'voice-recording-error';
        notice.setAttribute('data-voice-error', '1');
        notice.setAttribute('role', 'alert');
        panel.parentNode.insertBefore(notice, panel.nextSibling);
      }
      notice.textContent = message;
      notice.hidden = false;
      window.setTimeout(() => { if (notice) notice.hidden = true; }, 6500);
    };

    recordButton.addEventListener('click', async () => {
      if (recorder && recorder.state === 'recording') return;
      if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        showVoiceError('Voice recording is not supported by this browser. Please use a recent browser.');
        return;
      }

      clearVoiceAttachment();
      cancelled = false;
      try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const preferredTypes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
        const supported = preferredTypes.find((type) => MediaRecorder.isTypeSupported?.(type));
        recorder = supported ? new MediaRecorder(stream, { mimeType: supported }) : new MediaRecorder(stream);
        chunks = [];
        recorder.ondataavailable = (event) => { if (event.data?.size) chunks.push(event.data); };
        recorder.onstop = finishRecording;
        recorder.onerror = () => {
          cancelled = true;
          showVoiceError('The recording could not be completed. Please try again.');
          resetRecordingUI();
        };
        recorder.start(250);
        startedAt = Date.now();
        panel.hidden = false;
        form.classList.add('is-recording-active');
        if (composerRow) composerRow.hidden = true;
        recordButton.disabled = true;
        recordButton.classList.add('is-recording');
        recordButton.setAttribute('aria-label', 'Recording voice message');
        recordButton.title = 'Recording voice message';
        if (sendButton) sendButton.disabled = true;
        const timer = panel.querySelector('[data-voice-timer]');
        if (timer) timer.textContent = '00:00';
        timerId = window.setInterval(() => {
          const elapsed = Math.floor((Date.now() - startedAt) / 1000);
          if (timer) timer.textContent = formatTime(elapsed);
          if (elapsed >= maxSeconds) stopRecording(false);
        }, 250);
      } catch (error) {
        cleanupStream();
        panel.hidden = true;
        form.classList.remove('is-recording-active');
        if (composerRow) composerRow.hidden = false;
        recordButton.disabled = false;
        recordButton.classList.remove('is-recording');
        recordButton.setAttribute('aria-label', 'Record voice message');
        recordButton.title = 'Record voice message';
        if (sendButton) sendButton.disabled = false;

        let message = 'Microphone access could not be started. Please check your browser microphone permission and try again.';
        switch (error?.name) {
          case 'NotAllowedError':
            message = 'Microphone access was blocked. Allow microphone access for this site, then reload the page.';
            break;
          case 'NotFoundError':
            message = 'No microphone was found. Connect or enable a microphone, then try again.';
            break;
          case 'NotReadableError':
            message = 'Your microphone is already being used by another application.';
            break;
          case 'SecurityError':
            message = 'Microphone access was blocked for security reasons. Make sure the site uses HTTPS.';
            break;
        }
        showVoiceError(message);
      }
    });

    panel.querySelector('[data-voice-stop]')?.addEventListener('click', () => stopRecording(false));
    panel.querySelector('[data-voice-cancel]')?.addEventListener('click', () => stopRecording(true));

    form.addEventListener('submit', (event) => {
      if (recorder && recorder.state === 'recording') {
        event.preventDefault();
        showVoiceError('Stop the recording before sending it.');
      }
    });
  };

  setupVoiceRecorder(chatShell);
  if (attachmentInput && attachmentPreview) {
    attachmentInput.addEventListener('change', () => {
      attachmentPreview.innerHTML = '';
      const files = Array.from(attachmentInput.files || []);
      if (!files.length) {
        attachmentPreview.hidden = true;
        return;
      }
      files.forEach((file) => {
        const item = document.createElement('div');
        item.className = 'attachment-chip';
        item.textContent = `${file.name} - ${Math.max(1, Math.round(file.size / 1024))} KB`;
        attachmentPreview.appendChild(item);
      });
      attachmentPreview.hidden = false;
    });
  }

  const newMessageModal = document.querySelector('#newMessageModal');
  const newMessageForm = document.querySelector('[data-new-message-form]');
  if (newMessageModal && newMessageForm) {
    const caseInput = newMessageModal.querySelector('[data-default-case-input]');
    const recipientSelect = newMessageModal.querySelector('[data-recipient-select]');
    const messageInput = newMessageModal.querySelector('[data-message-input]');
    const errorBox = newMessageModal.querySelector('[data-modal-errors]');
    const recipientRole = newMessageModal.querySelector('[name="new_recipient_role"]');
    const attachmentButton = newMessageModal.querySelector('[data-modal-attachment-button]');
    const attachmentInputNew = newMessageModal.querySelector('[data-modal-attachment-input]');
    const attachmentName = newMessageModal.querySelector('[data-modal-attachment-name]');

    newMessageModal.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(newMessageModal);
        resetNewMessageModal(newMessageModal);
      });
    });

    if (attachmentButton && attachmentInputNew && attachmentButton.tagName !== 'LABEL') {
      attachmentButton.addEventListener('click', () => attachmentInputNew.click());
    }
    if (attachmentInputNew) {
      attachmentInputNew.addEventListener('change', () => {
        renderAttachmentPreview(attachmentName, Array.from(attachmentInputNew.files || []));
      });
    }

    if (recipientSelect && recipientRole) {
      const syncRecipientRole = () => {
        const selected = recipientSelect.selectedOptions[0];
        recipientRole.value = selected?.dataset.role || recipientRole.value || '';
      };
      recipientSelect.addEventListener('change', syncRecipientRole);
      syncRecipientRole();
    }

    if (recipientSelect && caseInput) {
      const syncCaseToRecipient = () => {
        const selected = recipientSelect.selectedOptions[0];
        const linkedCaseId = selected?.dataset.caseId || '';
        caseInput.value = linkedCaseId;
      };
      recipientSelect.addEventListener('change', syncCaseToRecipient);
      syncCaseToRecipient();
    }

    newMessageForm.addEventListener('submit', (event) => {
      const errors = [];
      const recipient = recipientSelect?.value || '';
      const message = messageInput?.value.trim() || '';
      const hasAttachment = Boolean(attachmentInputNew?.files && attachmentInputNew.files.length > 0);
      if (!recipient) errors.push('Select a recipient first.');
      if (!message && !hasAttachment) errors.push('Message or attachment is required.');
      if (errorBox) {
        errorBox.textContent = errors.join(' ');
        errorBox.hidden = errors.length === 0;
      }
      if (errors.length) {
        event.preventDefault();
      }
    });
  }
  }
})();

