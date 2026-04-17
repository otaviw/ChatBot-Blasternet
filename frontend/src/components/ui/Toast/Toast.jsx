import { Toaster } from 'react-hot-toast';
import './Toast.css';

function Toast() {
  return (
    <Toaster
      position="top-right"
      gutter={12}
      containerClassName="app-toast-container"
      toastOptions={{
        duration: 4200,
        className: 'app-toast',
        success: {
          className: 'app-toast app-toast--success',
        },
        error: {
          className: 'app-toast app-toast--error',
        },
      }}
    />
  );
}

export default Toast;
