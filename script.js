const LEAD_ENDPOINT = "./lead.php";

function syncFaqPanelHeights() {
  const items = Array.from(document.querySelectorAll(".faq-item"));

  items.forEach((item) => {
    const panel = item.querySelector(".faq-panel");

    if (!panel) {
      return;
    }

    if (item.classList.contains("is-open")) {
      panel.style.height = `${panel.scrollHeight}px`;
    } else {
      panel.style.height = "0px";
    }
  });
}

function setupFaqAccordion() {
  const items = Array.from(document.querySelectorAll(".faq-item"));

  if (!items.length) {
    return;
  }

  items.forEach((item) => {
    const trigger = item.querySelector(".faq-trigger");

    if (!trigger) {
      return;
    }

    trigger.addEventListener("click", () => {
      const isOpen = item.classList.contains("is-open");

      items.forEach((current) => {
        current.classList.remove("is-open");
        const currentTrigger = current.querySelector(".faq-trigger");
        if (currentTrigger) {
          currentTrigger.setAttribute("aria-expanded", "false");
        }
      });

      if (!isOpen) {
        item.classList.add("is-open");
        trigger.setAttribute("aria-expanded", "true");
      }

      syncFaqPanelHeights();
    });
  });

  syncFaqPanelHeights();
}

function setupFinalCtaQuiz() {
  const quizzes = Array.from(document.querySelectorAll(".js-quiz, .final-cta-quiz"));

  if (!quizzes.length) {
    return;
  }

  quizzes.forEach((quiz) => {
    const steps = Array.from(quiz.querySelectorAll(".final-cta-quiz-step"));
    const nextButtons = Array.from(quiz.querySelectorAll("[data-next-step]"));
    const backButtons = Array.from(quiz.querySelectorAll(".final-cta-back"));
    const contactButtons = Array.from(quiz.querySelectorAll("[data-contact-type]"));
    const contactInput = quiz.querySelector('input[name="contact"]');
    const consentInput = quiz.querySelector('input[name="privacy_consent"]');
    const submitButton = quiz.querySelector("[data-submit-lead]");
    const statusNode = quiz.querySelector(".quiz-status");

    function getStepChoice(stepNumber) {
      const step = quiz.querySelector(`.final-cta-quiz-step[data-step="${stepNumber}"]`);
      const activeOption = step ? step.querySelector(".final-cta-option.is-active") : null;
      return activeOption ? activeOption.textContent.trim() : "";
    }

    function setStatus(message, tone = "") {
      if (!statusNode) {
        return;
      }

      statusNode.textContent = message;
      statusNode.classList.remove("is-success", "is-error", "is-loading");

      if (tone) {
        statusNode.classList.add(`is-${tone}`);
      }
    }

    function openStep(stepNumber) {
      steps.forEach((step) => {
        step.classList.toggle("is-active", step.dataset.step === String(stepNumber));
      });
    }

    nextButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const parent = button.closest(".final-cta-quiz-step");
        const nextStep = Number(button.dataset.nextStep || "1");

        if (parent) {
          parent.querySelectorAll(".final-cta-option").forEach((option) => {
            option.classList.remove("is-active");
          });
        }

        button.classList.add("is-active");
        setStatus("");
        openStep(nextStep);
      });
    });

    backButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const parent = button.closest(".final-cta-quiz-step");
        const current = parent ? Number(parent.dataset.step || "1") : 1;
        openStep(Math.max(1, current - 1));
      });
    });

    contactButtons.forEach((button) => {
      button.addEventListener("click", () => {
        contactButtons.forEach((item) => item.classList.remove("is-active"));
        button.classList.add("is-active");

        if (contactInput) {
          contactInput.placeholder =
            button.dataset.contactType === "email"
              ? "hello@growa.ru"
              : "@username";
        }
      });
    });

    if (submitButton && contactInput) {
      submitButton.addEventListener("click", async () => {
        const contact = contactInput.value.trim();
        const contactTypeButton = quiz.querySelector("[data-contact-type].is-active");
        const contactType = contactTypeButton
          ? contactTypeButton.dataset.contactType || "telegram"
          : "telegram";

        if (!contact) {
          setStatus("Введите контакт, чтобы получить разбор.", "error");
          contactInput.focus();
          return;
        }

        if (consentInput && !consentInput.checked) {
          setStatus("Подтвердите согласие на обработку персональных данных.", "error");
          consentInput.focus();
          return;
        }

        const payload = {
          source: quiz.dataset.quizSource || "site",
          topic: getStepChoice(1),
          volume: getStepChoice(2),
          contact_type: contactType,
          contact,
          page: window.location.href,
          created_at: new Date().toISOString(),
        };

        submitButton.disabled = true;
          setStatus("Отправляем запрос...", "loading");

        try {
          const response = await fetch(LEAD_ENDPOINT, {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
          });

          const result = await response.json();

          if (!response.ok || !result.ok) {
            throw new Error(result.error || "Не удалось отправить заявку");
          }

          setStatus("Запрос отправлен. Скоро пришлём демо или КП.", "success");
          contactInput.value = "";
          openStep(1);
          quiz.querySelectorAll(".final-cta-option").forEach((option) => {
            option.classList.remove("is-active");
          });

          const firstStepFirst = quiz.querySelector('.final-cta-quiz-step[data-step="1"] .final-cta-option');
          const thirdStepFirst = quiz.querySelector('.final-cta-quiz-step[data-step="3"] [data-contact-type]');

          if (firstStepFirst) {
            firstStepFirst.classList.remove("is-active");
          }

          if (thirdStepFirst) {
            contactButtons.forEach((item) => item.classList.remove("is-active"));
            thirdStepFirst.classList.add("is-active");
            contactInput.placeholder = "@username";
          }

          if (consentInput) {
            consentInput.checked = false;
          }

          if (quiz.dataset.quizSource === "popup") {
            window.setTimeout(() => {
              const popup = document.querySelector(".conversion-popup");
              if (popup) {
                popup.classList.remove("is-open");
                popup.setAttribute("aria-hidden", "true");
                document.body.style.overflow = "";
              }
            }, 1200);
          }
        } catch (error) {
          setStatus("Не удалось отправить запрос. Попробуйте позже или напишите в Telegram.", "error");
        } finally {
          submitButton.disabled = false;
        }
      });
    }
  });
}

function setupHeroMenu() {
  const menu = document.querySelector(".hero-menu");
  const burger = document.querySelector(".hero-burger");

  if (!menu || !burger) {
    return;
  }

  const closeTargets = Array.from(menu.querySelectorAll("[data-menu-close]"));
  const links = Array.from(menu.querySelectorAll(".hero-menu-nav a"));

  function setMenuState(isOpen) {
    menu.classList.toggle("is-open", isOpen);
    menu.setAttribute("aria-hidden", String(!isOpen));
    burger.setAttribute("aria-expanded", String(isOpen));
    document.body.style.overflow = isOpen ? "hidden" : "";
  }

  burger.addEventListener("click", () => {
    const isOpen = menu.classList.contains("is-open");
    setMenuState(!isOpen);
  });

  closeTargets.forEach((target) => {
    target.addEventListener("click", () => setMenuState(false));
  });

  links.forEach((link) => {
    link.addEventListener("click", () => setMenuState(false));
  });

  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && menu.classList.contains("is-open")) {
      setMenuState(false);
    }
  });
}

function setupConversionPopup() {
  const popup = document.querySelector(".conversion-popup");
  const openTriggers = Array.from(document.querySelectorAll("[data-open-quiz]"));

  if (!popup || !openTriggers.length) {
    return;
  }

  const closeTargets = Array.from(popup.querySelectorAll("[data-popup-close]"));
  let hasShownAutoPopup = false;

  function setPopupState(isOpen) {
    popup.classList.toggle("is-open", isOpen);
    popup.setAttribute("aria-hidden", String(!isOpen));
    document.body.style.overflow = isOpen ? "hidden" : "";
  }

  openTriggers.forEach((trigger) => {
    trigger.addEventListener("click", (event) => {
      event.preventDefault();
      hasShownAutoPopup = true;
      setPopupState(true);
    });
  });

  closeTargets.forEach((target) => {
    target.addEventListener("click", () => setPopupState(false));
  });

  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && popup.classList.contains("is-open")) {
      setPopupState(false);
    }
  });

  if (window.innerWidth > 1024) {
    document.addEventListener("mouseout", (event) => {
      if (hasShownAutoPopup || event.clientY > 12) {
        return;
      }

      hasShownAutoPopup = true;
      setPopupState(true);
    });
  }

  window.setTimeout(() => {
    if (!hasShownAutoPopup && !popup.classList.contains("is-open")) {
      hasShownAutoPopup = true;
      setPopupState(true);
    }
  }, 35000);
}

function setupStickyWidget() {
  const widget = document.querySelector(".sticky-widget");

  if (!widget) {
    return;
  }

  const toggle = widget.querySelector(".sticky-widget-toggle");

  if (!toggle) {
    return;
  }

  toggle.addEventListener("click", () => {
    const isOpen = widget.classList.toggle("is-open");
    toggle.setAttribute("aria-expanded", String(isOpen));
  });

  document.addEventListener("click", (event) => {
    if (!widget.contains(event.target)) {
      widget.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
    }
  });
}

window.addEventListener("load", setupFaqAccordion);
window.addEventListener("resize", syncFaqPanelHeights);
window.addEventListener("load", setupFinalCtaQuiz);
window.addEventListener("load", setupHeroMenu);
window.addEventListener("load", setupConversionPopup);
window.addEventListener("load", setupStickyWidget);
