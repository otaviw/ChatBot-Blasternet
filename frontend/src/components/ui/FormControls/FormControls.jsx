import './FormControls.css';
import { cloneElement, isValidElement, useId } from 'react';

const CONTROL_CLASS =
  'app-input';

function mergeClasses(...classes) {
  return classes.filter(Boolean).join(' ');
}

function Field({ label, hint = '', className = '', children }) {
  const fieldId = useId();
  const inputId = `field-${fieldId}`;
  const hintId = hint ? `${inputId}-hint` : undefined;

  const childWithA11yProps =
    isValidElement(children)
      ? cloneElement(children, {
          id: children.props.id || inputId,
          'aria-describedby': hintId
            ? [children.props['aria-describedby'], hintId].filter(Boolean).join(' ')
            : children.props['aria-describedby'],
        })
      : children;

  return (
    <label className={mergeClasses('app-field-label', className)}>
      <span className="font-medium">{label}</span>
      {hint ? <span id={hintId} className="app-field-hint">{hint}</span> : null}
      {childWithA11yProps}
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
    <label className={mergeClasses('app-checkbox-label', className)}>
      <input
        type="checkbox"
        checked={checked}
        onChange={onChange}
        className="app-checkbox"
      />
      <span>{children}</span>
    </label>
  );
}

export { Field, TextInput, TextAreaInput, SelectInput, CheckboxField };

