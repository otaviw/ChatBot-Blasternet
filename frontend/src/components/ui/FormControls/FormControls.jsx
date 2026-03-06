import './FormControls.css';

const CONTROL_CLASS =
  'mt-1.5 w-full rounded-lg border border-[#e5e5e5] bg-white px-3 py-2.5 text-[15px] text-[#171717] outline-none transition placeholder:text-[#a3a3a3] focus:border-[#2563eb] focus:ring-2 focus:ring-[#2563eb]/20';

function mergeClasses(...classes) {
  return classes.filter(Boolean).join(' ');
}

function Field({ label, hint = '', className = '', children }) {
  return (
    <label className={mergeClasses('block text-sm font-medium text-[#525252]', className)}>
      <span className="font-medium">{label}</span>
      {hint ? <span className="ml-1 text-xs text-[#737373]">{hint}</span> : null}
      {children}
    </label>
  );
}

function TextInput({ className = '', ...props }) {
  return <input className={mergeClasses(CONTROL_CLASS, className)} {...props} />;
}

function TextAreaInput({ className = '', ...props }) {
  return <textarea className={mergeClasses(CONTROL_CLASS, className)} {...props} />;
}

function SelectInput({ className = '', children, ...props }) {
  return (
    <select className={mergeClasses(CONTROL_CLASS, className)} {...props}>
      {children}
    </select>
  );
}

function CheckboxField({ checked, onChange, children, className = '' }) {
  return (
    <label className={mergeClasses('inline-flex items-center gap-2 text-sm text-[#1f2937]', className)}>
      <input
        type="checkbox"
        checked={checked}
        onChange={onChange}
        className="h-4 w-4 rounded border-[#d4d4d4] text-[#2563eb] focus:ring-[#2563eb]/20"
      />
      <span>{children}</span>
    </label>
  );
}

export { Field, TextInput, TextAreaInput, SelectInput, CheckboxField };

