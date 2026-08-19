const registerForm = document.querySelector("#registerForm");
const fullName = document.querySelector("#fullName");
const registerEmail = document.querySelector("#registerEmail");
const registerPassword = document.querySelector("#registerPassword");
const confirmPassword = document.querySelector("#confirmPassword");
const terms = document.querySelector("#terms");
const registerMessage = document.querySelector("#registerMessage");

document.querySelectorAll("[data-toggle]").forEach((button) => {
  button.addEventListener("click", () => {
    const input = document.querySelector(`#${button.dataset.toggle}`);
    const isHidden = input.type === "password";
    input.type = isHidden ? "text" : "password";
    button.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
  });
});

function setError(input, message) {
  input.classList.toggle("invalid", Boolean(message));
  document.querySelector(`#${input.id}Error`).textContent = message;
}

registerForm.addEventListener("submit", (event) => {
  event.preventDefault();
  registerMessage.textContent = "";

  const nameValid = fullName.value.trim().length >= 3;
  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(registerEmail.value.trim());
  const passwordValid = registerPassword.value.length >= 6;
  const passwordsMatch = confirmPassword.value !== "" && confirmPassword.value === registerPassword.value;

  setError(fullName, nameValid ? "" : "Please enter your full name.");
  setError(registerEmail, emailValid ? "" : "Please enter a valid email address.");
  setError(registerPassword, passwordValid ? "" : "Use at least 6 characters.");
  setError(confirmPassword, passwordsMatch ? "" : "Passwords do not match.");
  document.querySelector("#termsError").textContent = terms.checked ? "" : "Please accept the terms to continue.";

  if (nameValid && emailValid && passwordValid && passwordsMatch && terms.checked) {
    registerMessage.textContent = "Account details are valid. Your registration form is ready to connect to the database.";
    registerMessage.classList.add("success-message");
  }
});

[fullName, registerEmail, registerPassword, confirmPassword].forEach((input) => {
  input.addEventListener("input", () => setError(input, ""));
});

terms.addEventListener("change", () => {
  document.querySelector("#termsError").textContent = "";
});
