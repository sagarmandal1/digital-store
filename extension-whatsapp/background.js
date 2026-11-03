chrome.action.onClicked.addListener(async (tab) => {
  if (!tab || !tab.id) return;
  try {
    await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      func: () => {
        const el = document.getElementById('ds-wa-panel');
        if (el) {
          el.classList.toggle('ds-hidden');
          if (!el.classList.contains('ds-hidden')) {
            document.dispatchEvent(new CustomEvent('ds-wa-boot'));
          }
        } else {
          document.dispatchEvent(new CustomEvent('ds-wa-boot'));
        }
      }
    });
  } catch (e) {}
});

chrome.commands.onCommand.addListener(async (command) => {
  if (command === 'toggle-panel') {
    const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tabs.length || !tabs[0].id) return;
    try {
      await chrome.scripting.executeScript({
        target: { tabId: tabs[0].id },
        func: () => {
          const el = document.getElementById('ds-wa-panel');
          if (el) {
            el.classList.toggle('ds-hidden');
            if (!el.classList.contains('ds-hidden')) {
              document.dispatchEvent(new CustomEvent('ds-wa-boot'));
            }
          } else {
            document.dispatchEvent(new CustomEvent('ds-wa-boot'));
          }
        }
      });
    } catch (e) {}
  }
});
