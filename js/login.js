'use strict';
const form = document.querySelector('#loginForm');
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const button = form.querySelector('button');
  const error = document.querySelector('#loginError');
  button.disabled = true;
  button.textContent = 'Entrando…';
  error.hidden = true;
  try {
    const response = await fetch('api/index.php?rota=login', {
      method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
      body: JSON.stringify({email: form.elements.email.value.trim(), senha: form.elements.senha.value})
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.erro || 'Não foi possível entrar.');
    location.replace('index.php');
  } catch (failure) {
    error.textContent = failure.message;
    error.hidden = false;
  } finally { button.disabled = false; button.textContent = 'Entrar'; }
});
