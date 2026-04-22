self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const url = event.notification.data?.url;
  if (!url) {
    return;
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clientList) => {
      for (const client of clientList) {
        try {
          if ('navigate' in client) {
            await client.navigate(url);
          }
          if ('focus' in client) {
            await client.focus();
          }
          return;
        } catch {
          // ignore and try next client
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(url);
      }

      return undefined;
    })
  );
});
