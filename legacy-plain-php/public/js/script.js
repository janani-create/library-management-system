const form = document.querySelector("#loginForm");
const email = document.querySelector("#email");
const password = document.querySelector("#password");
const passwordToggle = document.querySelector("#passwordToggle");
const formMessage = document.querySelector("#formMessage");

passwordToggle.addEventListener("click", () => {
  const isHidden = password.type === "password";
  password.type = isHidden ? "text" : "password";
  passwordToggle.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
});

function showError(input, message) {
  input.classList.toggle("invalid", Boolean(message));
  document.querySelector(`#${input.id}Error`).textContent = message;
}

form.addEventListener("submit", (event) => {
  event.preventDefault();
  formMessage.textContent = "";

  const emailIsValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
  const passwordIsValid = password.value.length >= 6;

  showError(email, emailIsValid ? "" : "Please enter a valid email address.");
  showError(password, passwordIsValid ? "" : "Password must be at least 6 characters.");

  if (emailIsValid && passwordIsValid) {
    formMessage.textContent = "Login details look good. Connect this form to your backend to continue.";
  }
});

[email, password].forEach((input) => {
  input.addEventListener("input", () => showError(input, ""));
});
